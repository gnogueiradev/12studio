<?php

namespace App\Store;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Variant;
use App\Support\LocalizedUrl;
use App\Support\PriceRange;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * O cartao de um produto na montra (grelhas, catalogo, relacionados).
 *
 * Campos escolhidos a mao: nunca o modelo. Custos, stock exato, gramagem,
 * tempos de impressao e o preco de revenda nao saem daqui — o cliente ve
 * so se ha para entrega imediata.
 */
final class ProductCard
{
    /** Dias em que um produto mostra "Novo". */
    public const NEW_DAYS = 30;

    /** Bolinhas de cor no cartao; as outras viram "+n". */
    public const MAX_SWATCHES = 5;

    /**
     * As relacoes que o make() le, para carregar de uma vez numa listagem.
     *
     * @return array<int|string, mixed>
     */
    public static function with(): array
    {
        return [
            ...Product::withTranslations(),
            'category' => fn ($query) => $query->with(Category::withTranslations()),
            'images',
            'variants' => fn ($query) => $query->visibleInStore()->with('color'),
        ];
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public static function query(Builder $query): Builder
    {
        return $query->visibleInStore()->with(self::with());
    }

    /**
     * @return array<string, mixed>
     */
    public static function make(Product $product, ?string $locale = null): array
    {
        /** @var Collection<int, Variant> $variants */
        $variants = $product->variants;
        $images = $product->images->sortBy([['is_primary', 'desc'], ['sort_order', 'asc']])->values();

        /** @var ProductImage|null $primary */
        $primary = $images->first();
        /** @var ProductImage|null $second */
        $second = $images->get(1);

        $colors = $variants
            ->pluck('color')
            ->filter()
            ->unique('id')
            ->sortBy('sort_order')
            ->values();

        $price = PriceRange::of($variants);
        $availability = self::availability($product, $variants);

        $name = (string) $product->translated('name', $locale);

        return [
            'id' => $product->id,
            'name' => $name,
            'slug' => $product->slug,
            'href' => LocalizedUrl::path('/produtos/'.$product->slug, $locale),
            'category' => $product->category?->translated('name', $locale),
            'price' => $price->toArray(),
            'image' => $primary?->responsive($name),
            'hoverImage' => $second?->responsive($name),
            'colors' => $colors->take(self::MAX_SWATCHES)->map(fn ($color): array => [
                'name' => $color->translatedName($locale),
                'hex' => $color->hex_color,
            ])->all(),
            'moreColors' => max(0, $colors->count() - self::MAX_SWATCHES),
            'availability' => $availability,
            'isNew' => $product->created_at !== null
                && $product->created_at->gt(Carbon::now()->subDays(self::NEW_DAYS)),
            'onSale' => $price->compareAtCents !== null,
        ];
    }

    /**
     * ready: em stock, segue em 1-2 dias. made_to_order / custom: produzido
     * para quem encomenda. sold_out: em stock sem unidades e sem aceitar
     * encomendas em espera.
     *
     * @param  Collection<int, Variant>  $variants
     */
    public static function availability(Product $product, Collection $variants): string
    {
        if ($product->isCustom()) {
            return 'custom';
        }

        if ($product->isMadeToOrder()) {
            return 'made_to_order';
        }

        if ($variants->contains(fn (Variant $variant): bool => $variant->available_stock > 0)) {
            return 'ready';
        }

        return $product->allow_backorder ? 'made_to_order' : 'sold_out';
    }
}
