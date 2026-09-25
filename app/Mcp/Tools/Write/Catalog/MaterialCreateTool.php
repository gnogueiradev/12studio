<?php

namespace App\Mcp\Tools\Write\Catalog;

use App\Http\Requests\Material\StoreMaterialRequest;
use App\Mcp\FormRequestRunner;
use App\Mcp\McpFormat;
use App\Mcp\Tools\WriteTool;
use App\Models\Material;
use App\Models\User;
use App\Services\MaterialService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('material_create')]
#[Title('Criar material')]
#[Description('Acrescenta um material de filamento (ex. "PLA Silk"), com o preço por kg em euros — que entra no custo de produção de cada variante — e as bobines em stock. Depois associa-lhe cores com color_update.')]
#[IsDestructive(false)]
class MaterialCreateTool extends WriteTool
{
    public function __construct(
        private FormRequestRunner $runner,
        private MaterialService $materials,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->max(60)->required(),
            'family' => $schema->string()->enum(Material::FAMILIES),
            'supplier' => $schema->string()->max(60),
            'price_per_kg' => $schema->string()->required()->description('Euros por kg, ex. "24,99".'),
            'spools_in_stock' => $schema->integer()->min(0)->max(65535),
            'min_spools' => $schema->integer()->min(0)->max(65535)->description('Alerta de stock baixo abaixo disto.'),
            'sort_order' => $schema->integer()->min(0)->max(65535),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $data = $this->runner->validate(StoreMaterialRequest::class, ['active' => true, ...$request->all()], $user);

        $material = $this->materials->store($data);
        $this->changes = ['created' => ['material_id' => $material->id]];

        return Response::structured([
            'message' => "Material \"{$material->name}\" criado.",
            'material' => [
                'id' => $material->id,
                'name' => $material->name,
                'price_per_kg' => McpFormat::money($material->price_per_kg_cents),
                'spools_in_stock' => $material->spools_in_stock,
            ],
        ]);
    }
}
