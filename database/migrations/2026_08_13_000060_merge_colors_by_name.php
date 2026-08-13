<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Colapsa as linhas duplicadas de cor numa so.
 *
 * Enquanto uma cor pertenceu a um material, "Preto" existia uma vez por
 * material: o indice era (material_id, name) e o backoffice agrupava as linhas
 * pelo NOME em runtime para as mostrar como uma cor so. Agora que a cor deixa
 * de ter material, esse grupo passa a ser mesmo uma linha — e as irmas sao
 * duplicados.
 *
 * NAO se esta a apagar uma cor: esta-se a apagar uma linha duplicada DA MESMA
 * cor. A identidade sobrevive na representante e todas as referencias vao para
 * la antes do delete. E a unica excecao a regra global de eliminacao logica em
 * todo o repositorio, e existe porque nao ha historial nenhum a preservar —
 * a linha que sai nunca foi uma cor diferente da que fica.
 *
 * Corre DEPOIS de 000050 (que precisa do `colors.material_id` intacto para
 * preencher o material das variantes) e ANTES de 000070 (que cria o indice
 * unico global em `colors.name` e rebentava com duplicados).
 *
 * A decisao toda vive em plan(), que e publico e puro: o risco desta migracao
 * esta em ESCOLHER quem sobrevive, nao nos dois updates que aplicam a escolha.
 * Ver tests/Unit/ColorMergePlanTest.php.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            $plan = $this->plan($this->read());

            foreach ($plan['survivors'] as $survivor) {
                DB::table('colors')->where('id', $survivor['id'])->update([
                    'name' => $survivor['name'],
                    'hex_color' => $survivor['hex_color'],
                    'image' => $survivor['image'],
                    'is_active' => $survivor['is_active'],
                    'sort_order' => $survivor['sort_order'],
                ]);
            }

            /*
             * Remapear ANTES de apagar, e nas duas tabelas: `variants.color_id`
             * e restrictOnDelete e o delete falhava; `product_images.color_id`
             * e nullOnDelete e a imagem perdia a cor em silencio.
             */
            foreach ($plan['remap'] as $loserId => $survivorId) {
                DB::table('variants')
                    ->where('color_id', $loserId)
                    ->update(['color_id' => $survivorId]);

                DB::table('product_images')
                    ->where('color_id', $loserId)
                    ->update(['color_id' => $survivorId]);
            }

            if ($plan['delete'] !== []) {
                DB::table('colors')->whereIn('id', $plan['delete'])->delete();
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * Vazio, e nao por esquecimento: as linhas irmas foram apagadas e as
     * variantes remapeadas: nao ha por onde reconstruir que cor pertencia a que
     * material nem que variante apontava para qual das linhas. Escrever um
     * down() que repusesse "alguma coisa" era fingir uma reversao que nao
     * existe. A rede e a copia do ficheiro .sqlite tirada antes de migrar.
     */
    public function down(): void
    {
        //
    }

    /**
     * Quem sobrevive, quem se remapeia e quem se apaga.
     *
     * Puro de proposito — recebe linhas, devolve decisoes, nao toca na base de
     * dados. E a unica parte desta migracao que se pode testar: `RefreshDatabase`
     * aplica sempre TODAS as migracoes, e nesse ponto `colors.material_id` ja
     * nao existe, o que torna impossivel montar o estado antigo num teste.
     *
     * @param  array<int, array{id: int, name: string, hex_color: string, image: string|null, is_active: bool, sort_order: int}>  $rows
     *                                                                                                                                   ordenadas por id
     * @return array{
     *     survivors: array<int, array{id: int, name: string, hex_color: string, image: string|null, is_active: bool, sort_order: int}>,
     *     remap: array<int, int>,
     *     delete: array<int, int>,
     * }
     */
    public function plan(array $rows): array
    {
        /** @var array<string, array<int, array{id: int, name: string, hex_color: string, image: string|null, is_active: bool, sort_order: int}>> $groups */
        $groups = [];

        foreach ($rows as $row) {
            /*
             * Insensivel a maiusculas porque o indice que vem a seguir e
             * COLLATE NOCASE: agrupar por nome exato deixava "Preto" e "preto"
             * sobreviverem os dois e o indice unico nao chegava a nascer.
             */
            $groups[mb_strtolower(trim($row['name']))][] = $row;
        }

        $merged = [];

        foreach ($groups as $group) {
            $merged[] = $this->merge($group);
        }

        /*
         * O sort_order antigo era um ordinal DENTRO de um material — a primeira
         * cor de cada material era 0 —, por isso dezenas de cores diferentes
         * empatavam a zero e a listagem saia por ordem arbitraria. Reatribuir
         * denso e o que lhe devolve significado agora que a ordem e global.
         */
        usort($merged, fn (array $a, array $b): int => [$a['sort_order'], $a['name']] <=> [$b['sort_order'], $b['name']]);

        $survivors = [];
        $remap = [];
        $delete = [];

        foreach ($merged as $index => $entry) {
            $survivors[] = [...$entry['survivor'], 'sort_order' => $index];

            foreach ($entry['losers'] as $loserId) {
                $remap[$loserId] = $entry['survivor']['id'];
                $delete[] = $loserId;
            }
        }

        return ['survivors' => $survivors, 'remap' => $remap, 'delete' => $delete];
    }

    /**
     * As linhas do mesmo nome numa so, e quem fica pelo caminho.
     *
     * @param  array<int, array{id: int, name: string, hex_color: string, image: string|null, is_active: bool, sort_order: int}>  $group
     * @return array{survivor: array{id: int, name: string, hex_color: string, image: string|null, is_active: bool, sort_order: int}, losers: array<int, int>, sort_order: int, name: string}
     */
    private function merge(array $group): array
    {
        /*
         * O menor id. E o mesmo representante que o backoffice ja usava
         * (ColorController::groups() fazia `$rows->min('id')`), por isso um
         * /admin/cores/{color} que alguem tenha guardado continua a abrir a
         * mesma cor. Com ele vem o nome e o hex: sao a forma canonica que a
         * paleta ja devolvia ("a linha mais antiga"), e hex divergentes dentro
         * do mesmo nome so existiam por acidente — o modal alinhava-os a cada
         * gravacao.
         */
        $survivor = $group[0];

        foreach ($group as $row) {
            if ($row['id'] < $survivor['id']) {
                $survivor = $row;
            }
        }

        $losers = [];
        $image = $survivor['image'];
        // OR: `is_active` queria dizer "existe NESTA bobine" e passa a querer
        // dizer "existe". Um AND arquivava cores em uso ativo nalgum material.
        $active = false;
        $sortOrder = PHP_INT_MAX;

        foreach ($group as $row) {
            $active = $active || $row['is_active'];
            $sortOrder = min($sortOrder, $row['sort_order']);

            // Primeiro nao-nulo por ordem de id. A coluna nunca teve formulario,
            // mas se alguem lhe escreveu por fora nao se perde.
            if ($image === null) {
                $image = $row['image'];
            }

            if ($row['id'] !== $survivor['id']) {
                $losers[] = $row['id'];
            }
        }

        return [
            // O price_per_kg_cents nao aparece aqui: os overrides de preco por
            // cor sao DESCARTADOS. E decisao de produto — o preco passa a ser
            // sempre o da bobine —, nao uma perda acidental da fusao.
            'survivor' => [...$survivor, 'image' => $image, 'is_active' => $active],
            'losers' => $losers,
            'sort_order' => $sortOrder,
            'name' => $survivor['name'],
        ];
    }

    /**
     * @return array<int, array{id: int, name: string, hex_color: string, image: string|null, is_active: bool, sort_order: int}>
     */
    private function read(): array
    {
        return DB::table('colors')
            ->select('id', 'name', 'hex_color', 'image', 'is_active', 'sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'hex_color' => (string) $row->hex_color,
                'image' => $row->image === null ? null : (string) $row->image,
                'is_active' => (bool) $row->is_active,
                'sort_order' => (int) $row->sort_order,
            ])
            ->all();
    }
};
