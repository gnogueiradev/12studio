<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\PageBuilder\Pages;
use App\Services\BrandSettings;
use App\Services\SettingService;
use App\Store\ProductDetail;
use App\Store\Seo;
use App\Store\StorePage;
use App\Support\LocalizedUrl;
use App\Support\PriceRange;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * A pagina de um produto: o template "product" do construtor com o produto
 * como prop de topo. Um produto que nao esta a venda (rascunho, arquivado,
 * sem variantes) da 404 — menos ao admin, que o ve com o aviso de rascunho.
 */
class ProductController extends Controller
{
    public function __invoke(Request $request, Pages $pages, StorePage $store, string $slug): Response
    {
        $product = Product::query()->visibleInStore()->where('slug', $slug)->with(ProductDetail::with())->first();
        $preview = false;

        if ($product === null && $request->user()?->isAdmin()) {
            $product = Product::query()->where('slug', $slug)->with(ProductDetail::with())->first();
            $preview = $product !== null;
        }

        abort_if($product === null, 404);

        $locale = app()->getLocale();
        $detail = ProductDetail::make($product, $locale);
        $title = $product->seo_title ?: $product->name;
        $image = $product->images->sortBy([['is_primary', 'desc'], ['sort_order', 'asc']])->first()?->og_url;

        return $store->render(
            $pages->system('product'),
            $request,
            props: [
                'title' => $title,
                'product' => $detail + ['preview' => $preview],
            ],
            product: $product,
            seo: Seo::make(
                path: '/produtos/'.$product->slug,
                title: $title,
                description: $product->seo_description ?: $product->description,
                image: $image,
                type: 'product',
                jsonLd: [$this->jsonLd($product, $detail, $image), Seo::breadcrumb(array_values(array_filter([
                    ['label' => trans('store.nav.catalog'), 'href' => LocalizedUrl::path('/produtos')],
                    $product->category ? ['label' => $product->category->name, 'href' => LocalizedUrl::path('/categoria/'.$product->category->slug)] : null,
                    ['label' => $product->name, 'href' => null],
                ])))],
                index: ! $preview,
            ),
        );
    }

    /**
     * O produto em schema.org. As pecas personalizadas nao levam preco: sao
     * sempre orcamentadas.
     *
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    private function jsonLd(Product $product, array $detail, ?string $image): array
    {
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $product->name,
            'description' => trim(strip_tags((string) $product->description)) ?: null,
            'image' => $image === null ? null : [url($image)],
            'url' => url($detail['href']),
            'brand' => ['@type' => 'Brand', 'name' => app(BrandSettings::class)->name()],
            'category' => $product->category?->name,
        ];

        $price = PriceRange::of($product->variants);

        if (! $product->isCustom() && $price->fromCents !== null) {
            $schema['offers'] = [
                '@type' => 'AggregateOffer',
                'priceCurrency' => app(SettingService::class)->currency(),
                'lowPrice' => number_format($price->fromCents / 100, 2, '.', ''),
                'highPrice' => number_format((int) $price->toCents / 100, 2, '.', ''),
                'offerCount' => $product->variants->count(),
                'availability' => match ($detail['availability']) {
                    'ready' => 'https://schema.org/InStock',
                    'made_to_order' => 'https://schema.org/MadeToOrder',
                    default => 'https://schema.org/OutOfStock',
                },
            ];
        }

        return array_filter($schema, fn (mixed $value): bool => $value !== null);
    }
}
