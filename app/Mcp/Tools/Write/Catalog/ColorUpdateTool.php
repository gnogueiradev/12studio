<?php

namespace App\Mcp\Tools\Write\Catalog;

use App\Http\Requests\Color\UpdateColorRequest;
use App\Mcp\FormRequestRunner;
use App\Mcp\Tools\WriteTool;
use App\Models\Color;
use App\Models\Material;
use App\Models\User;
use App\Models\Variant;
use App\Services\ColorService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('color_update')]
#[Title('Editar cor')]
#[Description('Altera uma cor: nome, tom, ordem ou os materiais em que existe. ATENÇÃO: tirar um material a uma cor esconde da loja as variantes dessa cor nesse material. Quando isso acontece, a alteração é recusada e diz quantas variantes seriam escondidas — só repetes com "confirm": true se o utilizador aprovar.')]
#[IsDestructive]
#[IsIdempotent]
class ColorUpdateTool extends WriteTool
{
    private const FIELDS = ['name', 'hex_color', 'sort_order', 'material_ids'];

    public function __construct(
        private FormRequestRunner $runner,
        private ColorService $colors,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required()->description('Id da cor (ver colors_list).'),
            'name' => $schema->string()->max(60),
            'hex_color' => $schema->string()->description('Tom, ex. "#FF7A00".'),
            'sort_order' => $schema->integer()->min(0)->max(65535),
            'material_ids' => $schema->array()->items($schema->integer())
                ->description('A lista COMPLETA de materiais em que tens a cor (substitui a atual).'),
            'confirm' => $schema->boolean()->description('Só depois de o utilizador aprovar esconder variantes.'),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $color = Color::query()->with('materials')->find((int) $request->get('id'));

        if ($color === null) {
            return Response::error('Cor não encontrada.');
        }

        $sent = Arr::only($request->all(), self::FIELDS);

        if ($sent === []) {
            return Response::error('Não indicaste nenhum campo para mudar.');
        }

        $current = [
            'name' => $color->name,
            'hex_color' => $color->hex_color,
            'sort_order' => $color->sort_order,
        ];

        $data = $this->runner->validate(UpdateColorRequest::class, [...$current, ...$sent], $user, ['color' => $color]);

        $materialIds = array_key_exists('material_ids', $sent)
            ? array_values(array_map(intval(...), (array) ($data['material_ids'] ?? [])))
            : null;

        // Quantas variantes a venda desapareceriam — a mesma conta do
        // ColorService::syncMaterials, feita antes, sem gravar.
        $wouldHide = $materialIds === null || $materialIds === []
            ? 0
            : Variant::query()
                ->where('color_id', $color->id)
                ->whereNotNull('material_id')
                ->whereNotIn('material_id', $materialIds)
                ->where('active', true)
                ->count();

        if ($wouldHide > 0 && ! $request->boolean('confirm')) {
            return Response::error(
                "Esta alteração esconde da loja {$wouldHide} variante(s) de {$color->name} nos materiais que tiras. "
                .'Nada foi gravado. Mostra isto ao utilizador e, só se ele confirmar, repete com "confirm": true.'
            );
        }

        $before = [...$current, 'material_ids' => $color->materials->pluck('id')->all()];

        $sync = DB::transaction(function () use ($color, $data, $sent, $materialIds): array {
            $this->colors->update($color, Arr::only($data, array_diff(array_keys($sent), ['material_ids'])));

            return $materialIds === null
                ? ['hidden' => 0, 'restored' => 0]
                : $this->colors->syncMaterials($color, $materialIds);
        });

        $color->refresh()->load('materials');

        $this->changes = [
            'color_id' => $color->id,
            'before' => $before,
            'after' => [
                'name' => $color->name,
                'hex_color' => $color->hex_color,
                'sort_order' => $color->sort_order,
                'material_ids' => $color->materials->pluck('id')->all(),
            ],
            'variants' => $sync,
        ];

        return Response::structured([
            'message' => 'Cor atualizada.',
            'color' => [
                'id' => $color->id,
                'name' => $color->name,
                'hex' => $color->hex_color,
                'materials' => $color->materials->map(fn (Material $material): string => $material->name)->values()->all(),
            ],
            'variants_hidden' => $sync['hidden'],
            'variants_restored' => $sync['restored'],
        ]);
    }
}
