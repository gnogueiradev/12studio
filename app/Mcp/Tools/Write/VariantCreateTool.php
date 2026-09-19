<?php

namespace App\Mcp\Tools\Write;

use App\Http\Requests\Variant\StoreVariantRequest;
use App\Mcp\FormRequestRunner;
use App\Mcp\Presenters\CatalogPresenter;
use App\Mcp\PriceGuard;
use App\Mcp\Tools\Write\Concerns\DescribesVariants;
use App\Mcp\Tools\WriteTool;
use App\Models\Product;
use App\Models\User;
use App\Services\VariantService;
use App\Support\VariantSku;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('variant_create')]
#[Title('Criar variante')]
#[Description('Acrescenta uma variante a um produto (por exemplo uma cor nova). O preço normal é obrigatório. Sem SKU, é gerado a partir do nome do produto. Nasce com stock 0 — usa stock_adjust para dar entrada. A cor tem de existir no material escolhido.')]
#[IsDestructive(false)]
class VariantCreateTool extends WriteTool
{
    use DescribesVariants;

    public function __construct(
        private FormRequestRunner $runner,
        private VariantService $variants,
        private PriceGuard $guard,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'product_id' => $schema->integer()->required()->description('Id do produto.'),
            ...$this->variantFieldsSchema($schema),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $product = Product::query()->find((int) $request->get('product_id'));

        if ($product === null) {
            return Response::error('Produto não encontrado.');
        }

        $input = [
            'sku' => VariantSku::next($product),
            'low_stock_threshold' => 3,
            'active' => true,
            ...Arr::only($request->all(), self::VARIANT_FIELDS),
            // O stock inicial entra pelo stock_adjust, com movimento proprio.
            'stock' => 0,
        ];

        $data = $this->runner->validate(StoreVariantRequest::class, $input, $user, ['product' => $product]);

        if (! $request->boolean('confirm')) {
            $concerns = $this->guard->concerns(null, $this->effectiveCents($data), $this->costProbe($data));

            if ($concerns !== []) {
                return Response::error(PriceGuard::message($concerns));
            }
        }

        $variant = $this->variants->store($product, $data, $user);
        $variant->load(['color', 'material']);

        $this->changes = ['created' => ['variant_id' => $variant->id, 'product_id' => $product->id]];

        return Response::structured([
            'message' => "Variante {$variant->sku} criada com stock 0.",
            'variant' => CatalogPresenter::variant($variant),
        ]);
    }
}
