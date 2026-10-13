<?php

namespace App\Mcp\Tools\Write;

use App\Mcp\Tools\WriteTool;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\ProductService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('product_delete')]
#[Title('Apagar produto')]
#[Description('Apaga um produto: sai das listas, da loja e das outras ferramentas, mas fica guardado — as encomendas e o histórico de stock não mexem, e recupera-se com product_restore. Para um produto que só saiu de linha, usa product_archive (continua à vista). Pede sempre confirmação: a primeira chamada diz o que está em jogo, e só com "confirm": true é que apaga.')]
#[IsDestructive]
#[IsIdempotent]
class ProductDeleteTool extends WriteTool
{
    public function __construct(
        private ProductService $products,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required()->description('Id do produto.'),
            'confirm' => $schema->boolean()->description('Só depois de o utilizador confirmar o que a primeira resposta mostrou.'),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $product = Product::query()->find((int) $request->get('id'));

        if ($product === null) {
            return Response::error('Produto não encontrado.');
        }

        // Sempre, e nao so em casos suspeitos como o PriceGuard: apagar tira o
        // produto da vista do dono, e quem o pediu pode ter querido arquivar.
        if (! $request->boolean('confirm')) {
            return Response::error($this->warning($product));
        }

        $before = $product->status;
        $this->products->delete($product);

        $this->changes = [
            'product_id' => $product->id,
            'before' => ['status' => $before, 'deleted' => false],
            'after' => ['status' => 'archived', 'deleted' => true],
        ];

        return Response::structured([
            'message' => "Produto \"{$product->name}\" apagado. Recupera-se com product_restore.",
            'id' => $product->id,
            'deleted' => true,
        ]);
    }

    private function warning(Product $product): string
    {
        $variants = $product->variants()->count();
        $orders = OrderItem::query()
            ->whereIn('variant_id', $product->variants()->select('id'))
            ->distinct()
            ->count('order_id');

        return sprintf(
            'Vais apagar "%s" (%s; %s). Sai das listas e da loja, mas fica guardado: as encomendas e o stock não mexem e recupera-se com product_restore. '
            .'Nada foi gravado. Mostra isto ao utilizador e, só se ele confirmar, repete com "confirm": true.',
            $product->name,
            $variants === 1 ? '1 variante' : "{$variants} variantes",
            match ($orders) {
                0 => 'sem encomendas',
                1 => 'aparece em 1 encomenda',
                default => "aparece em {$orders} encomendas",
            },
        );
    }
}
