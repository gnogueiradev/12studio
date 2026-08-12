<?php

namespace App\Support;

use App\Models\User;
use App\Models\Variant;

/**
 * As listas que o formulario de encomenda manual precisa: clientes com conta e
 * variantes vendaveis.
 *
 * Vive em Support porque o mesmo formulario e servido por dois controllers —
 * o OrderController (encomenda nova) e o OrderDraftController (retomar um
 * rascunho). Duplicar as duas consultas era duas oportunidades de as deixar
 * divergir.
 */
class ManualOrderOptions
{
    /**
     * Props partilhadas pela pagina admin/encomendas/create.
     *
     * @return array<string, mixed>
     */
    public static function props(): array
    {
        return [
            'customers' => self::customers(),
            'variants' => self::variants(),
            'defaultVatRate' => (int) config('shop.default_vat_rate', 23),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function customers(): array
    {
        return User::query()
            ->where('is_admin', false)
            ->with('addresses')
            ->orderBy('name')
            ->get()
            ->map(function (User $customer): array {
                $address = $customer->addresses->first();

                return [
                    'id' => $customer->id,
                    'name' => $customer->name,
                    'email' => $customer->email,
                    'phone' => $address?->phone,
                    'nif' => $address?->nif,
                    'address' => $address === null ? null : [
                        'line1' => $address->line1,
                        'line2' => $address->line2,
                        'postalCode' => $address->postal_code,
                        'city' => $address->city,
                        'country' => $address->country,
                    ],
                ];
            })
            ->all();
    }

    /**
     * Variantes ativas de produtos nao arquivados — o que o admin pode pôr
     * numa encomenda hoje.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function variants(): array
    {
        return Variant::query()
            ->where('active', true)
            ->whereHas('product', fn ($query) => $query->where('status', '!=', 'archived'))
            ->with(['product', 'color'])
            ->orderBy('sku')
            ->get()
            ->map(fn (Variant $variant): array => [
                'id' => $variant->id,
                'label' => trim(implode(' ', array_filter([
                    $variant->product->name,
                    $variant->color?->name,
                    $variant->size_label,
                ]))),
                // Sem o nome do produto — o modal agrupa por produto, e
                // repeti-lo dentro de cada variante dava "Vaso ondulado ·
                // Vaso ondulado PETG Natural".
                'variantLabel' => trim(implode(' ', array_filter([
                    $variant->color?->name,
                    $variant->size_label,
                ]))) ?: 'Única',
                'sku' => $variant->sku,
                'priceCents' => $variant->price_cents,
                'wholesalePriceCents' => $variant->wholesale_price_cents,
                'availableStock' => $variant->available_stock,
                'vatRate' => $variant->product->vat_rate,
                'fulfillmentMode' => $variant->product->fulfillment_mode,
                'productName' => $variant->product->name,
            ])
            ->all();
    }
}
