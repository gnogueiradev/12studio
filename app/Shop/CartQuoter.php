<?php

namespace App\Shop;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\Variant;
use App\Services\OrderTotalsCalculator;
use App\Services\PromoCodeValidator;
use App\Store\ProductDetail;
use App\Support\PromoCodeValidation;
use Illuminate\Support\Number;

/**
 * A unica conta do carrinho. O carrinho, a compra e a fotografia da encomenda
 * perguntam aqui: nenhum outro sitio calcula um preco ou um total, e os precos
 * saem da base de dados de cada vez, nunca do que o cliente enviou.
 */
final class CartQuoter
{
    public function __construct(
        private readonly OrderTotalsCalculator $totals,
        private readonly PromoCodeValidator $promoCodes,
    ) {}

    public function quote(Cart $cart, ?string $email = null): Quote
    {
        $items = $cart->items()->with([
            'variant.product' => fn ($query) => $query->with([...Product::withTranslations(), 'primaryImage']),
            'variant.color',
            'variant.material',
        ])->get();

        // Uma consulta para o carrinho todo: o que ja nao esta a venda sai daqui.
        $forSale = Buyable::forSale($items->map(fn (CartItem $item) => $item->variant));

        $lines = [];
        foreach ($items as $item) {
            $lines[] = $this->line($item, $forSale[$item->variant_id]);
        }

        // Uma linha com problema fica a mostra mas nao conta: o cliente ve o
        // que se passa sem pagar por ela.
        $counting = array_filter($lines, fn (QuoteLine $line): bool => $line->problem === null);
        $subtotal = array_sum(array_map(fn (QuoteLine $line): int => $line->lineCents, $counting));

        [$promoStatus, $promoMessage, $promoCodeId, $discount] = $this->promo($cart, $subtotal, $email);

        // Sem nada para enviar nao ha portes (nem falta para os portes gratis).
        $totals = $this->totals->calculate($subtotal, $discount, $counting === [] ? 0 : null);
        $freeLeft = $counting === [] ? 0 : max(0, (int) config('shop.shipping.free_above_cents') - $subtotal);

        return new Quote(
            lines: $lines,
            subtotalCents: $totals->subtotalCents,
            discountCents: $totals->discountCents,
            shippingCents: $totals->shippingCents,
            totalCents: $totals->totalCents,
            freeShippingLeftCents: $freeLeft,
            promoCode: $cart->promo_code,
            promoStatus: $promoStatus,
            promoMessage: $promoMessage,
            promoCodeId: $promoCodeId,
            problems: count($counting) !== count($lines),
            belowMinimum: $lines !== [] && $counting !== [] && $totals->totalCents < Quote::MINIMUM_TOTAL_CENTS,
        );
    }

    private function line(CartItem $item, bool $forSale): QuoteLine
    {
        $variant = $item->variant;
        $product = $variant->product;
        $check = Buyable::check($variant, $item->qty, $forSale);

        $personalization = $item->personalization === [] ? null : $item->personalization;
        $surcharge = $personalization === null ? 0 : (int) $product->personalization_surcharge_cents;

        return new QuoteLine(
            itemId: $item->id,
            variant: $variant,
            productName: $product->name,
            variantLabel: $this->variantLabel($variant),
            sku: $variant->sku,
            qty: $item->qty,
            maxQty: $check->maxQty,
            unitCents: $variant->price_cents,
            surchargeCents: $surcharge,
            lineCents: ($variant->price_cents + $surcharge) * $item->qty,
            vatRate: $product->vat_rate,
            personalization: $personalization,
            mode: $check->mode,
            imageUrl: $product->primaryImage?->url,
            leadDays: $check->mode === null ? null : ProductDetail::leadDays($product, $check->mode === 'in_stock' ? 'ready' : $check->mode),
            problem: $check->reason,
        );
    }

    /**
     * O que distingue a variante na compra: cor · material · tamanho, o que
     * ela tiver. So o tamanho deixava duas cores do mesmo tamanho iguais no
     * Stripe, no email e na encomenda. Com os nomes da lingua da loja, como o
     * resto da encomenda — o cliente ve-os traduzidos no CartPresenter.
     */
    private function variantLabel(Variant $variant): ?string
    {
        $locale = (string) config('locales.default');

        $label = implode(' · ', array_filter([
            $variant->color?->translatedName($locale),
            $variant->material?->translatedName($locale),
            $variant->size_label,
        ], fn (?string $part): bool => $part !== null && $part !== ''));

        return $label === '' ? null : $label;
    }

    /**
     * O codigo do carrinho contra o subtotal das linhas que contam. Uma
     * recusa nao trava a compra: so deixa o desconto a zero.
     *
     * @return array{0: ?string, 1: ?string, 2: ?int, 3: int} estado, mensagem, id do codigo, desconto
     */
    private function promo(Cart $cart, int $subtotal, ?string $email): array
    {
        if ($cart->promo_code === null || $cart->promo_code === '') {
            return [null, null, null, 0];
        }

        $result = $this->promoCodes->validate($cart->promo_code, $subtotal, $email, ['cart_id' => $cart->id]);

        if ($result->valid) {
            return ['applied', null, $result->promoCode?->id, $result->discountCents];
        }

        $status = $result->reason === PromoCodeValidation::EMAIL_REQUIRED ? 'pending_email' : 'refused';

        // O texto do validador e portugues (o backoffice usa-o assim): ao
        // cliente a mensagem vai no idioma dele, pela razao da recusa, e o
        // valor com os separadores dele — a mesma localizacao `intl` com que
        // a montra formata os precos.
        $locale = app()->getLocale();
        $message = trans('store.cart.promo.'.$result->reason, [
            'amount' => Number::format(
                ($result->promoCode->min_subtotal_cents ?? 0) / 100,
                precision: 2,
                locale: (string) config("locales.available.{$locale}.intl", $locale),
            ),
        ]);

        return [$status, $message, null, 0];
    }
}
