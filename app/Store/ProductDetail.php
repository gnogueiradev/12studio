<?php

namespace App\Store;

use App\Models\Color;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Variant;
use App\Support\LocalizedUrl;
use App\Support\PriceRange;
use Illuminate\Support\Collection;

/**
 * A pagina de um produto na montra. Como o ProductCard: campos escolhidos a
 * mao, sem custos, stock exato, gramagem nem tempos. De cada variante o
 * cliente ve so o preco e se ha para entrega imediata.
 */
final class ProductDetail
{
    /** Prazo de quem tem em stock: o texto "1-2 dias uteis" da montra. */
    public const READY_DAYS = [1, 2];

    /**
     * @return array<int|string, mixed>
     */
    public static function with(): array
    {
        return [
            'category',
            'images',
            'variants' => fn ($query) => $query->visibleInStore()->with(['color', 'material'])->orderByDesc('is_default')->orderBy('id'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function make(Product $product, ?string $locale = null): array
    {
        /** @var Collection<int, Variant> $variants */
        $variants = $product->variants;
        $availability = ProductCard::availability($product, $variants);
        $default = $variants->firstWhere('is_default', true) ?? $variants->first();

        return [
            'id' => $product->id,
            'name' => $product->name,
            'slug' => $product->slug,
            'href' => LocalizedUrl::path('/produtos/'.$product->slug, $locale),
            'description' => (string) $product->description,
            'category' => $product->category === null ? null : [
                'name' => $product->category->name,
                'href' => LocalizedUrl::path('/categoria/'.$product->category->slug, $locale),
            ],
            'price' => PriceRange::of($variants)->toArray(),
            'availability' => $availability,
            'leadDays' => self::leadDays($product, $availability),
            'isCustom' => $product->isCustom(),
            'images' => self::images($product),
            'colors' => $variants->pluck('color')->filter()->unique('id')->sortBy('sort_order')->values()
                ->map(fn (Color $color): array => ['id' => $color->id, 'name' => $color->name, 'hex' => $color->hex_color])
                ->all(),
            'materials' => $variants->pluck('material')->filter()->unique('id')->sortBy('sort_order')->values()
                ->map(fn (Material $material): array => ['id' => $material->id, 'name' => $material->name])
                ->all(),
            'sizes' => $variants->pluck('size_label')->filter()->unique()->values()->all(),
            'variants' => $variants->map(fn (Variant $variant): array => [
                'id' => $variant->id,
                'colorId' => $variant->color_id,
                'materialId' => $variant->material_id,
                'size' => $variant->size_label,
                'price' => $variant->price_cents,
                'compareAt' => $variant->isOnSale() ? $variant->compare_at_cents : null,
                'ready' => $product->fulfillment_mode === 'in_stock' && $variant->available_stock > 0,
                'specs' => array_filter([
                    'length' => $variant->length_mm,
                    'width' => $variant->width_mm,
                    'height' => $variant->height_mm,
                    'weight' => $variant->product_weight_grams,
                ], fn (?int $value): bool => $value !== null && $value > 0),
            ])->values()->all(),
            'defaultVariantId' => $default?->id,
            'personalization' => self::personalization($product),
        ];
    }

    /**
     * O prazo que a montra promete: "1-2 dias" pronto a enviar; os dias de
     * producao declarados no produto, por encomenda. Null quando nao ha
     * nada declarado — melhor nao prometer do que prometer mal.
     *
     * @return array{int, int}|null
     */
    private static function leadDays(Product $product, string $availability): ?array
    {
        if ($availability === 'ready') {
            return self::READY_DAYS;
        }

        if ($product->production_time_days !== null && $product->production_time_days > 0) {
            $days = $product->production_time_days;

            return [$days, $days + 2];
        }

        return null;
    }

    /**
     * Todas as fotos, a principal primeiro, com a cor ou a variante a que
     * pertencem — a galeria troca para as da cor escolhida.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function images(Product $product): array
    {
        return $product->images
            ->sortBy([['is_primary', 'desc'], ['sort_order', 'asc']])
            ->values()
            ->map(fn (ProductImage $image): array => [
                'id' => $image->id,
                'colorId' => $image->color_id,
                'variantId' => $image->variant_id,
                ...$image->responsive($product->name),
            ])
            ->all();
    }

    /**
     * Os campos de personalizacao (nome gravado, numero...): so o que o
     * cliente precisa para os preencher no pedido.
     *
     * @return array{fields: array<int, array<string, mixed>>, surcharge: int|null}|null
     */
    private static function personalization(Product $product): ?array
    {
        $fields = collect((array) $product->personalization_fields)
            ->filter(fn (mixed $field): bool => is_array($field) && is_string($field['key'] ?? null) && is_string($field['label'] ?? null))
            ->map(fn (array $field): array => [
                'key' => $field['key'],
                'label' => $field['label'],
                'type' => ($field['type'] ?? 'text') === 'number' ? 'number' : 'text',
                'required' => (bool) ($field['required'] ?? false),
                'maxLength' => is_int($field['maxLength'] ?? null) ? $field['maxLength'] : null,
            ])
            ->values()
            ->all();

        if ($fields === []) {
            return null;
        }

        return [
            'fields' => $fields,
            'surcharge' => $product->personalization_surcharge_cents,
        ];
    }
}
