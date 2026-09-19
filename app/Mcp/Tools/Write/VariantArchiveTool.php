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

#[Name('variant_archive')]
#[Title('Esconder variante')]
#[Description('Esconde uma variante da loja. Não apaga: stock e histórico ficam, e volta com variant_update "active": true. A variante predefinida não se esconde — escolhe outra primeiro com variant_set_default.')]
#[IsDestructive]
#[IsIdempotent]
class VariantArchiveTool extends WriteTool
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

        if ($variant->is_default) {
            return Response::error('Esta é a variante predefinida. Escolhe outra com variant_set_default antes de a esconder.');
        }

        $this->variants->archive($variant);
        $this->changes = ['variant_id' => $variant->id, 'before' => ['active' => true], 'after' => ['active' => false]];

        return Response::structured([
            'message' => "Variante {$variant->sku} escondida.",
            'variant_id' => $variant->id,
        ]);
    }
}
