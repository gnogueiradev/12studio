<?php

namespace App\Mcp\Tools\Read;

use App\Mcp\McpFormat;
use App\Mcp\Tools\StudioTool;
use App\Models\Color;
use App\Models\Material;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('materials_list')]
#[Title('Listar materiais')]
#[Description('Materiais de filamento (PLA, PETG…): preço por kg, bobines em stock e mínimo, estado (active, low_stock, archived) e as cores disponíveis em cada um — a matriz cor × material que decide que variantes um produto pode ter.')]
#[IsReadOnly]
#[IsIdempotent]
class MaterialsListTool extends StudioTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'include_archived' => $schema->boolean()->description('Incluir os materiais arquivados (por omissão, não).'),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $data = $request->validate(['include_archived' => ['nullable', 'boolean']]);

        $materials = Material::query()
            ->with('colors')
            ->when(! ($data['include_archived'] ?? false), fn ($query) => $query->where('active', true))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return Response::structured([
            'materials' => $materials->map(fn (Material $material): array => [
                'id' => $material->id,
                'name' => $material->name,
                'family' => $material->family,
                'supplier' => $material->supplier,
                'price_per_kg' => McpFormat::money($material->price_per_kg_cents),
                'spools_in_stock' => $material->spools_in_stock,
                'min_spools' => $material->min_spools,
                'state' => $material->state(),
                'colors' => $material->colors
                    ->map(fn (Color $color): array => ['id' => $color->id, 'name' => $color->name])
                    ->values()
                    ->all(),
            ])->all(),
        ]);
    }
}
