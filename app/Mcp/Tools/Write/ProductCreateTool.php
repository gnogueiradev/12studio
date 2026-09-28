<?php

namespace App\Mcp\Tools\Write;

use App\Http\Requests\Product\StoreProductRequest;
use App\Mcp\FormRequestRunner;
use App\Mcp\Presenters\CatalogPresenter;
use App\Mcp\Tools\WriteTool;
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

#[Name('product_create')]
#[Title('Criar produto')]
#[Description('Cria um produto novo, por omissão como rascunho (draft) — só fica à venda se pedires status "active". Opcionalmente gera logo as variantes a partir de cores × materiais × tamanhos, todas com os mesmos preços; as combinações cor/material que não existem no catálogo ficam de fora. As variantes nascem com stock 0 (usa stock_adjust depois). Preços em euros, IVA incluído. Sem fotografias: essas adicionam-se no backoffice.')]
#[IsDestructive(false)]
class ProductCreateTool extends WriteTool
{
    public function __construct(
        private FormRequestRunner $runner,
        private ProductService $products,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->max(120)->required()->description('Nome do produto.'),
            'slug' => $schema->string()->max(140)->description('Endereço (minúsculas e hífens). Vazio = gerado a partir do nome.'),
            'category_id' => $schema->integer()->description('Categoria (ver categories_list).'),
            'description' => $schema->string()->max(10000)->description('Descrição em HTML simples (<p>, <strong>, <ul>…). É limpa antes de gravar.'),
            'tags' => $schema->array()->items($schema->string()->max(60))->max(20)->description('Etiquetas (criadas se não existirem).'),
            'status' => $schema->string()->enum(Product::STATUSES)->description('draft (por omissão), active ou archived.'),
            'featured' => $schema->boolean()->description('Destacado na loja.'),
            'vat_rate' => $schema->integer()->min(0)->max(100)->description('Taxa de IVA em % (por omissão 23).'),
            'fulfillment_mode' => $schema->string()->enum(Product::FULFILLMENT_MODES)->description('in_stock (por omissão), made_to_order ou custom.'),
            'production_time_days' => $schema->integer()->min(0)->max(60)->description('Dias de produção (feito por encomenda).'),
            'allow_backorder' => $schema->boolean()->description('Aceitar encomendas sem stock.'),
            'variants' => $schema->object([
                'color_ids' => $schema->array()->items($schema->integer())->description('Cores (ver colors_list).'),
                'material_ids' => $schema->array()->items($schema->integer())->description('Materiais (ver materials_list).'),
                'sizes' => $schema->array()->items($schema->string()->max(60))->description('Tamanhos (opcional).'),
                'normal_price' => $schema->string()->description('Preço normal em euros, ex. "24,90". Obrigatório com cores/materiais.'),
                'sale_price' => $schema->string()->description('Preço promocional (abaixo do normal).'),
                'wholesale_price' => $schema->string()->description('Preço de revenda. Obrigatório com cores/materiais.'),
            ])->description('Matriz de variantes a gerar (opcional).'),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $input = [
            'status' => 'draft',
            'vat_rate' => 23,
            'fulfillment_mode' => 'in_stock',
            ...$request->all(),
        ];

        $data = $this->runner->validate(StoreProductRequest::class, $input, $user);

        $product = $this->products->store($data);
        $product->load(['category', 'tags', 'images', 'variants.color', 'variants.material']);

        $this->changes = ['created' => ['product_id' => $product->id, 'variants' => $product->variants->count()]];

        return Response::structured([
            'message' => "Produto criado ({$product->status}) com {$product->variants->count()} variante(s).",
            'product' => CatalogPresenter::productDetail($product),
        ]);
    }
}
