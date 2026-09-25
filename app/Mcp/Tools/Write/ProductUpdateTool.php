<?php

namespace App\Mcp\Tools\Write;

use App\Http\Requests\Product\UpdateProductRequest;
use App\Mcp\FormRequestRunner;
use App\Mcp\Presenters\CatalogPresenter;
use App\Mcp\Tools\WriteTool;
use App\Models\Product;
use App\Models\User;
use App\Services\ProductService;
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

#[Name('product_update')]
#[Title('Editar produto')]
#[Description('Altera os dados de um produto (não as variantes nem os preços — para isso usa variant_update). Só precisas de mandar os campos que mudam; o resto fica como está. Mandar "tags" substitui todas as etiquetas. Mudar o status para "active" põe o produto à venda.')]
#[IsDestructive(false)]
#[IsIdempotent]
class ProductUpdateTool extends WriteTool
{
    /** Campos do produto que esta ferramenta pode mudar. */
    private const FIELDS = [
        'name', 'slug', 'category_id', 'description', 'tags', 'status', 'featured',
        'vat_rate', 'fulfillment_mode', 'production_time_days', 'allow_backorder',
        'max_open_production_qty',
    ];

    public function __construct(
        private FormRequestRunner $runner,
        private ProductService $products,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required()->description('Id do produto.'),
            'name' => $schema->string()->max(120),
            'slug' => $schema->string()->max(140)->description('Novo endereço. Cuidado: muda o link público do produto.'),
            'category_id' => $schema->integer()->description('Categoria (ver categories_list).'),
            'description' => $schema->string()->max(10000)->description('Descrição em HTML simples. É limpa antes de gravar.'),
            'tags' => $schema->array()->items($schema->string()->max(60))->max(20)->description('Substitui TODAS as etiquetas.'),
            'status' => $schema->string()->enum(Product::STATUSES),
            'featured' => $schema->boolean(),
            'vat_rate' => $schema->integer()->min(0)->max(100),
            'fulfillment_mode' => $schema->string()->enum(Product::FULFILLMENT_MODES),
            'production_time_days' => $schema->integer()->min(0)->max(60),
            'allow_backorder' => $schema->boolean(),
            'max_open_production_qty' => $schema->integer()->min(1)->max(65535),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $product = Product::query()->with('tags')->find((int) $request->get('id'));

        if ($product === null) {
            return Response::error('Produto não encontrado.');
        }

        $sent = Arr::only($request->all(), self::FIELDS);

        if ($sent === []) {
            return Response::error('Não indicaste nenhum campo para mudar.');
        }

        // O pedido do backoffice quer o produto inteiro: o que nao veio e o
        // que ja la esta. As etiquetas so entram se vierem (null = nao mexer).
        $current = [
            ...Arr::only($product->toArray(), array_diff(self::FIELDS, ['tags'])),
            'featured' => $product->featured,
            'allow_backorder' => $product->allow_backorder,
        ];

        $data = $this->runner->validate(UpdateProductRequest::class, [...$current, ...$sent], $user, ['product' => $product]);

        // So o que foi pedido segue para o servico — nunca reescrever campos
        // com os valores que acabaram de ser lidos (e a descricao nem passa
        // pelo purificador se nao mudou).
        $data = Arr::only($data, array_keys($sent));

        $before = Arr::only($product->toArray(), array_keys($data));
        $before['tags'] = $product->tags->pluck('name')->all();

        $this->products->update($product, $data);
        $product->refresh()->load(['category', 'tags', 'images', 'variants.color', 'variants.material']);

        $after = Arr::only($product->toArray(), array_keys($data));
        $after['tags'] = $product->tags->pluck('name')->all();

        $this->changes = ['product_id' => $product->id, 'before' => $before, 'after' => $after];

        return Response::structured([
            'message' => 'Produto atualizado.',
            'changed' => array_keys($data),
            'product' => CatalogPresenter::productDetail($product),
        ]);
    }
}
