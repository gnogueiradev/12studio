<?php

namespace App\Mcp\Tools\Write\Catalog;

use App\Http\Requests\Color\StoreColorRequest;
use App\Mcp\FormRequestRunner;
use App\Mcp\Tools\WriteTool;
use App\Models\Material;
use App\Models\User;
use App\Services\ColorService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('color_create')]
#[Title('Criar cor')]
#[Description('Acrescenta uma cor de filamento, com o tom em hexadecimal e os materiais em que a tens (ex. Rosa em PLA e PETG). Sem materiais, a cor existe mas não gera variantes. Se já houver uma cor arquivada com o mesmo nome, é essa que volta.')]
#[IsDestructive(false)]
class ColorCreateTool extends WriteTool
{
    public function __construct(
        private FormRequestRunner $runner,
        private ColorService $colors,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->max(60)->required(),
            'hex_color' => $schema->string()->required()->description('Tom, ex. "#FF7A00".'),
            'material_ids' => $schema->array()->items($schema->integer())->description('Materiais em que tens esta cor (ver materials_list).'),
            'sort_order' => $schema->integer()->min(0)->max(65535),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $data = $this->runner->validate(StoreColorRequest::class, $request->all(), $user);

        $color = $this->colors->store($data);

        // Cor nova (ou acabada de restaurar): sincronizar nao esconde nada que
        // esteja a venda, porque ela nao tem variantes ativas — mas pode
        // devolver as que o catalogo escondeu antes.
        $sync = isset($data['material_ids'])
            ? $this->colors->syncMaterials($color, array_map(intval(...), (array) $data['material_ids']))
            : ['hidden' => 0, 'restored' => 0];

        $color->load('materials');
        $this->changes = ['created' => ['color_id' => $color->id], 'variants' => $sync];

        return Response::structured([
            'message' => "Cor \"{$color->name}\" criada.",
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
