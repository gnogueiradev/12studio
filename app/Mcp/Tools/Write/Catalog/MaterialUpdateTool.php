<?php

namespace App\Mcp\Tools\Write\Catalog;

use App\Http\Requests\Material\UpdateMaterialRequest;
use App\Mcp\FormRequestRunner;
use App\Mcp\McpFormat;
use App\Mcp\Tools\WriteTool;
use App\Models\Material;
use App\Models\User;
use App\Services\MaterialService;
use App\Support\Money;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('material_update')]
#[Title('Editar material')]
#[Description('Altera um material: nome, família, fornecedor, preço por kg (muda o custo — não os preços — das variantes que o usam), bobines em stock, mínimo, ordem, ou arquiva-o com "active": false. Manda só o que muda.')]
#[IsDestructive(false)]
#[IsIdempotent]
class MaterialUpdateTool extends WriteTool
{
    private const FIELDS = ['name', 'family', 'supplier', 'price_per_kg', 'spools_in_stock', 'min_spools', 'active', 'sort_order'];

    public function __construct(
        private FormRequestRunner $runner,
        private MaterialService $materials,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required()->description('Id do material (ver materials_list).'),
            'name' => $schema->string()->max(60),
            'family' => $schema->string()->enum(Material::FAMILIES),
            'supplier' => $schema->string()->max(60),
            'price_per_kg' => $schema->string()->description('Euros por kg, ex. "24,99".'),
            'spools_in_stock' => $schema->integer()->min(0)->max(65535),
            'min_spools' => $schema->integer()->min(0)->max(65535),
            'active' => $schema->boolean()->description('false = arquivado (sai do seletor; as variantes ficam).'),
            'sort_order' => $schema->integer()->min(0)->max(65535),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $material = Material::query()->find((int) $request->get('id'));

        if ($material === null) {
            return Response::error('Material não encontrado.');
        }

        $sent = Arr::only($request->all(), self::FIELDS);

        if ($sent === []) {
            return Response::error('Não indicaste nenhum campo para mudar.');
        }

        $current = [
            ...Arr::only($material->toArray(), array_diff(self::FIELDS, ['price_per_kg', 'active'])),
            'price_per_kg' => Money::toDecimal($material->price_per_kg_cents),
            'active' => $material->active,
        ];

        $data = $this->runner->validate(UpdateMaterialRequest::class, [...$current, ...$sent], $user, ['material' => $material]);
        $data = Arr::only($data, array_keys($sent));

        $before = Arr::only($current, array_keys($data));
        $this->materials->update($material, $data);
        $material->refresh();

        $this->changes = [
            'material_id' => $material->id,
            'before' => $before,
            'after' => Arr::only([
                ...$material->toArray(),
                'price_per_kg' => Money::toDecimal($material->price_per_kg_cents),
            ], array_keys($data)),
        ];

        return Response::structured([
            'message' => 'Material atualizado.',
            'material' => [
                'id' => $material->id,
                'name' => $material->name,
                'price_per_kg' => McpFormat::money($material->price_per_kg_cents),
                'spools_in_stock' => $material->spools_in_stock,
                'active' => $material->active,
                'state' => $material->state(),
            ],
        ]);
    }
}
