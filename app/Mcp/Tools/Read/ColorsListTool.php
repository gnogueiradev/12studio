<?php

namespace App\Mcp\Tools\Read;

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

#[Name('colors_list')]
#[Title('Listar cores')]
#[Description('Cores de filamento, com o tom (hex), o estado (active, no_material = ainda sem filamento associado, archived) e em que materiais existe cada uma. Uma cor só gera variantes nos materiais em que existe.')]
#[IsReadOnly]
#[IsIdempotent]
class ColorsListTool extends StudioTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'include_archived' => $schema->boolean()->description('Incluir as cores arquivadas (por omissão, não).'),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $data = $request->validate(['include_archived' => ['nullable', 'boolean']]);

        $colors = Color::query()
            ->with('materials')
            ->when(! ($data['include_archived'] ?? false), fn ($query) => $query->where('is_active', true))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return Response::structured([
            'colors' => $colors->map(fn (Color $color): array => [
                'id' => $color->id,
                'name' => $color->name,
                'hex' => $color->hex_color,
                'state' => $color->state(),
                'materials' => $color->materials
                    ->map(fn (Material $material): array => ['id' => $material->id, 'name' => $material->name])
                    ->values()
                    ->all(),
            ])->all(),
        ]);
    }
}
