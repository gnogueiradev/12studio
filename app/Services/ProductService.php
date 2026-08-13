<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Tag;
use App\Support\Slug;
use App\Support\VariantSku;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Mews\Purifier\Facades\Purifier;

class ProductService
{
    public function __construct(
        private VariantService $variantService,
        private ImageService $imageService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function store(array $data): Product
    {
        $images = $this->pullImages($data);

        // Os ficheiros vao para o disco ANTES de a transacao abrir: escrever
        // dez ficheiros de cinco megabytes com o unico escritor do SQLite na
        // mao era prende-lo o tempo todo do upload. Ver ImageService::put.
        $paths = $this->imageService->put($images);

        return DB::transaction(function () use ($data, $paths): Product {
            $tags = $this->pullTags($data);
            $variants = $this->pullVariantSeed($data);
            $data = $this->sanitizeDescription($data);

            $data['slug'] = Slug::unique(
                Product::class,
                $this->slugSource($data, (string) $data['name']),
            );

            $product = Product::query()->create($data);

            $this->imageService->attach($product, $paths);
            $this->syncTags($product, $tags ?? []);
            $this->generateVariants($product, $variants);

            return $product;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Product $product, array $data): Product
    {
        return DB::transaction(function () use ($product, $data): Product {
            $tags = $this->pullTags($data);
            $data = $this->sanitizeDescription($data);

            $slug = $this->slugSource($data, (string) ($data['name'] ?? $product->name));

            // Um slug escrito a mao e um URL que ja pode estar partilhado —
            // so se regenera quando muda mesmo. Mudar o nome do produto nao
            // lhe mexe.
            if ($slug !== $product->slug) {
                $data['slug'] = Slug::unique(Product::class, $slug, $product->id);
            }

            $product->update($data);

            if ($tags !== null) {
                $this->syncTags($product, $tags);
            }

            return $product;
        });
    }

    /**
     * Regra global de eliminacao: produtos nunca sao apagados fisicamente —
     * arquivar tira-os da montra mas preserva variantes, movimentos de stock
     * e itens de encomendas (historial comercial).
     */
    public function archive(Product $product): void
    {
        $product->update(['status' => 'archived']);
    }

    /**
     * Desarquivar devolve o produto a rascunho, NAO a ativo: restaurar e uma
     * correcao de arquivo, e pô-lo direto na montra seria republicar sem
     * ninguem ter pedido. Quem o quiser vendavel outra vez publica-o no
     * formulario, com os precos e o stock a vista.
     */
    public function restore(Product $product): void
    {
        $product->update(['status' => 'draft']);
    }

    /**
     * Matriz do modal de novo produto: cor x material x tamanho, tres eixos
     * independentes. Cada combinacao da uma variante, todas com os mesmos tres
     * precos. Sao um molde — o que difere entre variantes (stock, gramagem,
     * tempo de impressao) edita-se depois, uma a uma.
     *
     * Cor e material sao obrigatorios: sao os dois que definem uma peca
     * imprimivel — que tom, e em que filamento. Sem um deles nao ha matriz
     * nenhuma. O tamanho e opcional, e o `[null]` e o que faz o produto
     * cartesiano degenerar nesse caso sem precisar de um segundo ramo.
     *
     * A ordem dos ciclos (cor por fora, material no meio, tamanho por dentro) e
     * a mesma da pre-visualizacao do modal, em product-create-dialog.tsx. Se uma
     * mudar sem a outra, a matriz que o admin viu deixa de ser a que se gravou.
     *
     * O stock entra sempre a zero: a primeira contagem tem de passar pelo
     * StockService para ficar registada como movimento `initial`.
     *
     * @param  array<string, mixed>|null  $seed
     */
    private function generateVariants(Product $product, ?array $seed): void
    {
        if ($seed === null) {
            return;
        }

        /** @var array<int, int> $colorIds */
        $colorIds = $seed['color_ids'] ?? [];
        /** @var array<int, int> $materialIds */
        $materialIds = $seed['material_ids'] ?? [];

        if ($colorIds === [] || $materialIds === []) {
            return;
        }

        /** @var array<int, string> $sizes */
        $sizes = $seed['sizes'] ?? [];
        $sizes = $sizes === [] ? [null] : $sizes;

        $combos = [];

        foreach ($colorIds as $colorId) {
            foreach ($materialIds as $materialId) {
                foreach ($sizes as $size) {
                    $combos[] = [
                        'color_id' => $colorId,
                        'material_id' => $materialId,
                        'size_label' => $size,
                    ];
                }
            }
        }

        // Todas as referencias de uma vez: geradas em ciclo, cada uma via as
        // anteriores ja inseridas nesta transacao e a numeracao saltava.
        $skus = VariantSku::series($product, count($combos));

        foreach ($combos as $index => $combo) {
            $this->variantService->store($product, [
                ...$combo,
                'sku' => $skus[$index],
                // As chaves que o VariantService::normalizePrices() espera: e
                // la que a promocao troca de lugar com o preco normal.
                'normal_price' => (string) $seed['normal_price'],
                'sale_price' => $seed['sale_price'] ?? null,
                'wholesale_price' => $seed['wholesale_price'] ?? null,
                'stock' => 0,
            ]);
        }
    }

    /**
     * Etiquetas por nome -> ids no pivot, criando as que ainda nao existem.
     * A chave e o slug: "Natal", "natal" e "NATAL" sao a mesma etiqueta.
     *
     * @param  array<int, string>  $names
     */
    public function syncTags(Product $product, array $names): void
    {
        $ids = collect($names)
            ->map(fn (string $name): string => trim($name))
            ->filter()
            // Desambigua ANTES de ir a BD: "Natal" e "natal" dariam o mesmo
            // slug e o firstOrCreate devolveria a mesma linha duas vezes.
            ->keyBy(fn (string $name): string => str($name)->slug()->value())
            ->reject(fn (string $name, string $slug): bool => $slug === '')
            ->map(fn (string $name, string $slug): int => Tag::query()->firstOrCreate(
                ['slug' => $slug],
                ['name' => $name],
            )->id)
            ->values()
            ->all();

        $product->tags()->sync($ids);
    }

    /**
     * A descricao e HTML vindo do editor e vai acabar renderizada com
     * dangerouslySetInnerHTML na montra. Passa pela lista branca do perfil
     * `product` (config/purifier.php) ANTES de tocar na base de dados — o que
     * fica guardado ja e seguro, e nao ha nenhum ponto de leitura por onde
     * possa escapar sem limpeza.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function sanitizeDescription(array $data): array
    {
        if (! array_key_exists('description', $data)) {
            return $data;
        }

        $html = trim((string) ($data['description'] ?? ''));

        // O TipTap devolve "<p></p>" para um editor vazio — isso e nada.
        $clean = $html === '' ? '' : trim(Purifier::clean($html, 'product'));

        $data['description'] = in_array($clean, ['', '<p></p>'], true) ? null : $clean;

        return $data;
    }

    /**
     * Slug vazio (ou ausente) = "gera a partir do nome" — o comportamento por
     * omissao. O admin so escreve um quando quer um URL especifico.
     *
     * @param  array<string, mixed>  $data
     */
    private function slugSource(array $data, string $name): string
    {
        $slug = trim((string) ($data['slug'] ?? ''));

        return $slug === '' ? $name : $slug;
    }

    /**
     * Tira as tags do payload — nao sao uma coluna de `products`, so podem
     * ser gravadas depois de o produto existir. Null distingue "nao vieram no
     * pedido" (nao mexer) de "vieram vazias" (limpar todas).
     *
     * @param  array<string, mixed>  $data
     * @return array<int, string>|null
     */
    private function pullTags(array &$data): ?array
    {
        if (! array_key_exists('tags', $data)) {
            return null;
        }

        /** @var array<int, string> $tags */
        $tags = $data['tags'] ?? [];
        unset($data['tags']);

        return $tags;
    }

    /**
     * Tira as fotografias do payload, pela mesma razao das etiquetas: nao sao
     * colunas de `products`, e uma imagem so pode ser gravada depois de haver
     * um produto a que pertenca. So o modal de criar as manda — o de editar
     * carrega a galeria a parte, pelo ProductImageController.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, UploadedFile>
     */
    private function pullImages(array &$data): array
    {
        if (! array_key_exists('images', $data)) {
            return [];
        }

        /** @var array<int, UploadedFile> $images */
        $images = $data['images'] ?? [];
        unset($data['images']);

        return $images;
    }

    /**
     * Tira a matriz de variantes do payload, pela mesma razao das etiquetas:
     * nao sao colunas de `products` e so podem ser gravadas depois de o
     * produto existir. Null = nao veio no pedido (o formulario completo de
     * edicao nunca a envia).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function pullVariantSeed(array &$data): ?array
    {
        if (! array_key_exists('variants', $data)) {
            return null;
        }

        /** @var array<string, mixed>|null $seed */
        $seed = $data['variants'];
        unset($data['variants']);

        return $seed;
    }
}
