<?php

namespace App\Mcp\Presenters;

use App\Mcp\McpFormat;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Variant;

/**
 * Produtos e variantes no formato do MCP. Um sitio so, para que a listagem,
 * o detalhe e as respostas das escritas (Fase 3) digam o mesmo da mesma
 * maneira.
 */
final class CatalogPresenter
{
    /**
     * Linha de listagem: o suficiente para escolher, sem variantes.
     *
     * @return array<string, mixed>
     */
    public static function productSummary(Product $product): array
    {
        $variants = $product->variants;
        $prices = $variants->where('active', true)->map(fn (Variant $variant): int => $variant->price_cents);

        return [
            'id' => $product->id,
            'name' => $product->name,
            'slug' => $product->slug,
            'status' => $product->status,
            'category' => $product->category?->name,
            'featured' => $product->featured,
            'fulfillment_mode' => $product->fulfillment_mode,
            'variants' => $variants->count(),
            'price_from' => McpFormat::moneyOrNull($prices->min()),
            'price_to' => McpFormat::moneyOrNull($prices->max()),
            'available_stock' => $variants->where('active', true)->sum(fn (Variant $variant): int => $variant->available_stock),
        ];
    }

    /**
     * Ficha completa, com variantes, imagens e etiquetas.
     *
     * @return array<string, mixed>
     */
    public static function productDetail(Product $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'slug' => $product->slug,
            'status' => $product->status,
            'category' => $product->category === null ? null : [
                'id' => $product->category->id,
                'name' => $product->category->name,
            ],
            'tags' => $product->tags->pluck('name')->values()->all(),
            'featured' => $product->featured,
            'description_html' => $product->description,
            'vat_rate' => $product->vat_rate,
            'fulfillment_mode' => $product->fulfillment_mode,
            'production_time_days' => $product->production_time_days,
            'allow_backorder' => $product->allow_backorder,
            'max_open_production_qty' => $product->max_open_production_qty,
            'personalization_fields' => $product->personalization_fields ?? [],
            'personalization_surcharge' => McpFormat::moneyOrNull($product->personalization_surcharge_cents),
            'seo_title' => $product->seo_title,
            'seo_description' => $product->seo_description,
            'images' => $product->images->map(fn (ProductImage $image): array => [
                'id' => $image->id,
                'url' => $image->url,
                'alt' => $image->alt,
                'is_primary' => $image->is_primary,
                'variant_id' => $image->variant_id,
            ])->values()->all(),
            'variants' => $product->variants
                ->sortByDesc('is_default')
                ->map(fn (Variant $variant): array => self::variant($variant))
                ->values()
                ->all(),
            'updated_at' => $product->updated_at?->format('Y-m-d H:i'),
        ];
    }

    /**
     * Uma variante. Precos como o admin os pensa — normal e promocional —
     * e nao como a BD os guarda (price_cents e o efetivo).
     *
     * @return array<string, mixed>
     */
    public static function variant(Variant $variant): array
    {
        return [
            'id' => $variant->id,
            'sku' => $variant->sku,
            'color' => $variant->color?->name,
            'color_id' => $variant->color_id,
            'material' => $variant->material?->name,
            'material_id' => $variant->material_id,
            'size' => $variant->size_label,
            'is_default' => $variant->is_default,
            'active' => $variant->active,
            'normal_price' => McpFormat::money($variant->normalPriceCents()),
            'sale_price' => McpFormat::moneyOrNull($variant->salePriceCents()),
            'wholesale_price' => McpFormat::moneyOrNull($variant->wholesale_price_cents),
            'stock' => $variant->stock,
            'reserved_stock' => $variant->reserved_stock,
            'available_stock' => $variant->available_stock,
            'low_stock_threshold' => $variant->low_stock_threshold,
            'low_stock' => $variant->isLowStock(),
            'production' => [
                'filament_weight_grams' => $variant->filament_weight_grams,
                'printing_time_minutes' => $variant->printing_time_minutes,
                'active_labor_minutes' => $variant->active_labor_minutes,
                'printer_profile_id' => $variant->printer_profile_id,
                'components_cost' => McpFormat::moneyOrNull($variant->components_cost_cents),
                'packaging_cost' => McpFormat::moneyOrNull($variant->packaging_cost_cents),
            ],
        ];
    }
}
