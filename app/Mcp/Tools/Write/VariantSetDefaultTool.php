<?php

namespace App\Mcp\Tools\Write;

use App\Mcp\Tools\WriteTool;
use App\Models\User;
use App\Models\Variant;
use App\Services\VariantService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('variant_set_default')]
#[Title('Tornar variante predefinida')]
#[Description('Escolhe a variante que a loja mostra por omissão (e cujo preço aparece na listagem). A antiga predefinida deixa de o ser.')]
#[IsDestructive(false)]
#[IsIdempotent]
class VariantSetDefaultTool extends WriteTool
{
    public function __construct(
        private VariantService $variants,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'variant_id' => $schema->integer()->required()->description('Id da variante.'),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $variant = Variant::query()->find((int) $request->get('variant_id'));

        if ($variant === null) {
            return Response::error('Variante não encontrada.');
        }

        if (! $variant->active) {
            return Response::error('Esta variante está escondida. Ativa-a primeiro (variant_update com "active": true).');
        }

        $previous = Variant::query()
            ->where('product_id', $variant->product_id)
            ->where('is_default', true)
            ->value('id');

        $this->variants->setDefault($variant);
        $this->changes = ['product_id' => $variant->product_id, 'before' => ['default_variant_id' => $previous], 'after' => ['default_variant_id' => $variant->id]];

        return Response::structured([
            'message' => "A variante {$variant->sku} passa a ser a predefinida.",
            'variant_id' => $variant->id,
        ]);
    }
}
