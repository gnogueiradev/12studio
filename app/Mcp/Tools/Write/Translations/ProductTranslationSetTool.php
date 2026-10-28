<?php

namespace App\Mcp\Tools\Write\Translations;

use App\Mcp\Tools\WriteTool;
use App\Models\Product;
use App\Models\User;
use App\Services\TranslationService;
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

#[Name('product_translation_set')]
#[Title('Traduzir produto')]
#[Description('Grava um produto noutro idioma da montra (es, en ou fr): nome, descrição (HTML simples), título e descrição para motores de busca, e os rótulos dos campos de personalização. Manda só o que muda. O português edita-se com product_update. O endereço (slug) é o mesmo em todos os idiomas.')]
#[IsDestructive(false)]
#[IsIdempotent]
class ProductTranslationSetTool extends WriteTool
{
    private const FIELDS = ['name', 'description', 'seo_title', 'seo_description'];

    public function __construct(
        private TranslationService $translations,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required()->description('Id do produto (ver products_list).'),
            'locale' => $schema->string()->required()->description('es, en ou fr.'),
            'name' => $schema->string()->max(TranslationService::LIMITS['name']),
            'description' => $schema->string()->max(TranslationService::LIMITS['description']),
            'seo_title' => $schema->string()->max(TranslationService::LIMITS['seo_title']),
            'seo_description' => $schema->string()->max(TranslationService::LIMITS['seo_description']),
            'personalization_labels' => $schema->object()->description('Chave do campo de personalização => rótulo traduzido (ver product_get).'),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $product = Product::query()->find((int) $request->get('id'));

        if ($product === null) {
            return Response::error('Produto não encontrado.');
        }

        $locale = (string) $request->get('locale');
        $fields = Arr::only($request->all(), self::FIELDS);
        $labels = $request->get('personalization_labels');

        if (is_array($labels)) {
            $fields['personalization'] = $labels;
        }

        if ($fields === []) {
            return Response::error('Não indicaste nenhum campo para traduzir.');
        }

        $translation = $this->translations->setProduct($product, $locale, $fields);
        $this->changes = ['product_id' => $product->id, 'locale' => $locale, 'fields' => array_keys($fields)];

        return Response::structured([
            'message' => 'Tradução gravada.',
            'translation' => [
                'product_id' => $product->id,
                'locale' => $locale,
                'name' => $translation->name,
                'status' => $this->translations->productStatus($product->load('translations'), $locale),
            ],
        ]);
    }
}
