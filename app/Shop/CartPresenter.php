<?php

namespace App\Shop;

use App\Models\Cart;
use App\Support\LocalizedUrl;

/**
 * O carrinho com a forma que a página espera (CartData em
 * resources/js/types/shop.ts): preços em cêntimos, textos no idioma da página
 * e um `problem` por linha que já não se pode comprar. As contas são do
 * CartQuoter; aqui só se arruma o resultado.
 */
final class CartPresenter
{
    /**
     * Sem carrinho (o visitante ainda não pôs nada) devolve-se o vazio, sem
     * tocar na base de dados.
     *
     * @return array<string, mixed>
     */
    public static function make(?Cart $cart, ?string $email = null): array
    {
        if ($cart === null) {
            return self::empty();
        }

        $quote = app(CartQuoter::class)->quote($cart, $email);
        $locale = app()->getLocale();

        return [
            'lines' => array_map(fn (QuoteLine $line): array => self::line($line, $locale), $quote->lines),
            'count' => array_sum(array_map(fn (QuoteLine $line): int => $line->qty, $quote->lines)),
            'subtotalCents' => $quote->subtotalCents,
            'discountCents' => $quote->discountCents,
            'shippingCents' => $quote->shippingCents,
            'totalCents' => $quote->totalCents,
            'freeShippingLeftCents' => $quote->freeShippingLeftCents,
            'promo' => $quote->promoCode === null || $quote->promoStatus === null ? null : [
                'code' => $quote->promoCode,
                'status' => $quote->promoStatus,
                'message' => $quote->promoMessage,
            ],
            'belowMinimum' => $quote->belowMinimum,
            'canCheckout' => $quote->canCheckout(),
        ];
    }

    /**
     * Só as peças que o cabeçalho conta: o número de peças no carrinho, sem
     * calcular preços.
     */
    public static function count(?Cart $cart): int
    {
        return $cart === null ? 0 : (int) $cart->items()->sum('qty');
    }

    /**
     * @return array<string, mixed>
     */
    private static function empty(): array
    {
        return [
            'lines' => [],
            'count' => 0,
            'subtotalCents' => 0,
            'discountCents' => 0,
            'shippingCents' => 0,
            'totalCents' => 0,
            'freeShippingLeftCents' => 0,
            'promo' => null,
            'belowMinimum' => false,
            'canCheckout' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function line(QuoteLine $line, string $locale): array
    {
        $variant = $line->variant;
        $product = $variant->product;

        $label = implode(' · ', array_filter([
            $variant->color?->translatedName($locale),
            $variant->material?->translatedName($locale),
            $variant->size_label,
        ], fn (?string $part): bool => $part !== null && $part !== ''));

        return [
            'id' => $line->itemId,
            'variantId' => $variant->id,
            'name' => (string) $product->translated('name', $locale),
            'label' => $label === '' ? null : $label,
            'href' => LocalizedUrl::path("/produtos/{$product->slug}?variante={$variant->id}", $locale),
            'image' => $line->imageUrl,
            'qty' => $line->qty,
            'maxQty' => $line->maxQty,
            'unitCents' => $line->unitCents,
            'surchargeCents' => $line->surchargeCents,
            'lineCents' => $line->lineCents,
            'personalization' => self::personalization($line, $locale),
            'mode' => $line->mode,
            'leadDays' => $line->leadDays,
            // Uma quantidade fora do intervalo não chega a ficar guardada;
            // se chegar, o cliente vê o mesmo que num excesso de stock.
            'problem' => $line->problem === Buyable::BAD_QTY ? Buyable::OVER_STOCK : $line->problem,
        ];
    }

    /**
     * Cada valor guardado com o rótulo do campo no idioma da página; um
     * campo que o produto já não tem mostra-se pela chave.
     *
     * @return list<array{label: string, value: string}>
     */
    private static function personalization(QuoteLine $line, string $locale): array
    {
        $labels = [];

        foreach ($line->variant->product->personalizationFieldList() as $field) {
            $translated = $field['label_i18n'][$locale] ?? null;

            $labels[(string) $field['key']] = is_string($translated) && $translated !== ''
                ? $translated
                : (is_string($field['label'] ?? null) ? $field['label'] : (string) $field['key']);
        }

        $pairs = [];

        foreach ($line->personalization ?? [] as $key => $value) {
            $pairs[] = ['label' => $labels[$key] ?? (string) $key, 'value' => (string) $value];
        }

        return $pairs;
    }
}
