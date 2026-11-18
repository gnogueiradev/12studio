<?php

namespace App\Shop;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\PromoCode;
use App\Models\Variant;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Tudo o que muda um carrinho. Nao calcula precos (e do CartQuoter) e nao
 * cria nada em leituras: so o `add` cria o carrinho, e so depois de validar.
 * Cada escrita faz touch ao carrinho, que e o que o mantem vivo ate ao prune.
 */
class CartService
{
    public function find(?string $token): ?Cart
    {
        if ($token === null || $token === '') {
            return null;
        }

        return Cart::query()->where('token', $token)->first();
    }

    /**
     * @param  array<string, mixed>  $personalization  o que o cliente escreveu, por limpar
     *
     * @throws ValidationException
     */
    public function add(?Cart $cart, Variant $variant, int $qty, array $personalization): Cart
    {
        $clean = $this->personalizationFor($variant->product, $personalization);
        $hash = CartItem::hashFor($clean);

        $existing = $cart?->items()
            ->where('variant_id', $variant->id)
            ->where('personalization_hash', $hash)
            ->first();

        if ($existing === null && $cart !== null && $cart->items()->count() >= (int) config('shop.cart.max_lines')) {
            throw ValidationException::withMessages([
                'cart' => 'O carrinho não leva mais artigos diferentes.',
            ]);
        }

        $this->assertBuyable($variant, ($existing->qty ?? 0) + $qty);

        $cart ??= Cart::query()->create(['token' => Str::random(64)]);

        if ($existing === null) {
            $cart->items()->create([
                'variant_id' => $variant->id,
                'qty' => $qty,
                'personalization' => $clean === [] ? null : $clean,
                'personalization_hash' => $hash,
            ]);
        } else {
            $existing->update(['qty' => $existing->qty + $qty]);
        }

        $cart->touch();

        return $cart;
    }

    /**
     * @throws ValidationException
     */
    public function setQty(CartItem $item, int $qty): void
    {
        $this->assertBuyable($item->variant, $qty);

        $item->update(['qty' => $qty]);
        $item->cart->touch();
    }

    public function remove(CartItem $item): void
    {
        $cart = $item->cart;

        $item->delete();
        $cart->touch();
    }

    public function setPromoCode(Cart $cart, ?string $code): void
    {
        $code = $code === null ? '' : PromoCode::normalizeCode($code);

        $cart->update(['promo_code' => $code === '' ? null : $code]);
        $cart->touch();
    }

    public function clear(Cart $cart): void
    {
        $cart->items()->delete();
        $cart->update(['promo_code' => null]);
        $cart->touch();
    }

    /**
     * Fica so com os campos que o produto tem, limpos: sem espacos nem HTML,
     * sem vazios. As falhas vem todas juntas, com a chave `personalization.<campo>`.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     *
     * @throws ValidationException
     */
    public function personalizationFor(Product $product, array $input): array
    {
        $clean = [];
        $errors = [];

        foreach ($product->personalizationFieldList() as $field) {
            $key = (string) $field['key'];
            $label = is_string($field['label'] ?? null) ? $field['label'] : $key;
            $raw = $input[$key] ?? null;
            $value = is_scalar($raw) ? trim(strip_tags((string) $raw)) : '';

            if ($value === '') {
                if (! empty($field['required'])) {
                    $errors["personalization.{$key}"] = "Preenche o campo \"{$label}\".";
                }

                continue;
            }

            if (($field['type'] ?? 'text') === 'number' && ! is_numeric($value)) {
                $errors["personalization.{$key}"] = "O campo \"{$label}\" tem de ser um número.";

                continue;
            }

            if (is_int($field['maxLength'] ?? null) && mb_strlen($value) > $field['maxLength']) {
                $errors["personalization.{$key}"] = "O campo \"{$label}\" aceita no máximo {$field['maxLength']} caracteres.";

                continue;
            }

            $clean[$key] = $value;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $clean;
    }

    /**
     * A quantidade pedida tem de passar pela regra unica do que se compra.
     *
     * @throws ValidationException
     */
    private function assertBuyable(Variant $variant, int $qty): void
    {
        $check = Buyable::check($variant, $qty);

        if ($check->ok) {
            return;
        }

        $message = match ($check->reason) {
            Buyable::BAD_QTY => 'A quantidade tem de ser entre 1 e '.(int) config('shop.cart.max_qty').'.',
            Buyable::OVER_STOCK => 'Só temos '.$check->maxQty.' em stock.',
            Buyable::SOLD_OUT => 'Este artigo está esgotado.',
            default => 'Este artigo não se pode comprar.',
        };

        throw ValidationException::withMessages(['qty' => $message]);
    }
}
