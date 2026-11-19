<?php

namespace App\Shop;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\PromoCode;
use App\Models\Variant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Tudo o que muda um carrinho. Nao calcula precos (e do CartQuoter) e nao
 * cria nada em leituras: so o `add` cria o carrinho, e so depois de validar.
 * Cada escrita faz touch ao carrinho, que e o que o mantem vivo ate ao prune.
 */
class CartService
{
    /** Teto absoluto de cada valor de personalizacao, diga o produto o que disser. */
    public const MAX_PERSONALIZATION_LENGTH = 200;

    /** O tamanho da coluna carts.promo_code. */
    private const MAX_PROMO_CODE_LENGTH = 40;

    public function find(?string $token): ?Cart
    {
        if ($token === null || $token === '') {
            return null;
        }

        return Cart::query()->where('token', $token)->first();
    }

    /** O carrinho do cookie deste pedido, se houver; nunca cria. */
    public function fromRequest(Request $request): ?Cart
    {
        return $this->find($request->cookies->getString((string) config('shop.cart.cookie')));
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

        return DB::transaction(function () use ($cart, $variant, $qty, $clean, $hash): Cart {
            if ($cart === null) {
                $this->assertBuyable($variant, $qty);

                $cart = new Cart;
                $cart->token = Str::random(64);
                $cart->save();
            }

            try {
                $this->writeLine($cart, $variant, $qty, $clean, $hash);
            } catch (UniqueConstraintViolationException) {
                // Um duplo clique: o outro pedido criou esta linha entre a
                // consulta e a escrita. Agora ja existe, soma-se a ela.
                $this->writeLine($cart, $variant, $qty, $clean, $hash);
            }

            $cart->touch();

            return $cart;
        });
    }

    /**
     * @throws ValidationException
     * @throws ModelNotFoundException quando a linha nao e deste carrinho
     */
    public function setQty(Cart $cart, CartItem $item, int $qty): void
    {
        $this->assertOwns($cart, $item);
        $this->assertBuyable($item->variant, $qty);

        $item->update(['qty' => $qty]);
        $cart->touch();
    }

    /**
     * @throws ModelNotFoundException quando a linha nao e deste carrinho
     */
    public function remove(Cart $cart, CartItem $item): void
    {
        $this->assertOwns($cart, $item);

        $item->delete();
        $cart->touch();
    }

    /**
     * @throws ValidationException
     */
    public function setPromoCode(Cart $cart, ?string $code): void
    {
        $code = $code === null ? '' : PromoCode::normalizeCode($code);

        if (mb_strlen($code) > self::MAX_PROMO_CODE_LENGTH) {
            throw ValidationException::withMessages([
                'promo_code' => trans('store.cart.errors.promo_too_long', ['max' => self::MAX_PROMO_CODE_LENGTH]),
            ]);
        }

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
        $locale = app()->getLocale();

        foreach ($product->personalizationFieldList() as $field) {
            $key = (string) $field['key'];
            $label = $this->labelOf($field, $key, $locale);
            $raw = $input[$key] ?? null;
            $value = is_scalar($raw) ? trim(strip_tags((string) $raw)) : '';
            $max = is_int($field['maxLength'] ?? null)
                ? min($field['maxLength'], self::MAX_PERSONALIZATION_LENGTH)
                : self::MAX_PERSONALIZATION_LENGTH;

            if ($value === '') {
                if (! empty($field['required'])) {
                    $errors["personalization.{$key}"] = trans('store.cart.errors.required', ['label' => $label]);
                }

                continue;
            }

            if (($field['type'] ?? 'text') === 'number' && ! is_numeric($value)) {
                $errors["personalization.{$key}"] = trans('store.cart.errors.number', ['label' => $label]);

                continue;
            }

            if (mb_strlen($value) > $max) {
                $errors["personalization.{$key}"] = trans('store.cart.errors.too_long', ['label' => $label, 'max' => $max]);

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
     * O rotulo no idioma do cliente, como a pagina do produto o mostra.
     *
     * @param  array<string, mixed>  $field
     */
    private function labelOf(array $field, string $key, string $locale): string
    {
        $translated = $field['label_i18n'][$locale] ?? null;

        if (is_string($translated) && $translated !== '') {
            return $translated;
        }

        return is_string($field['label'] ?? null) ? $field['label'] : $key;
    }

    /**
     * Soma a quantidade a linha que ja la esta, ou cria-a, sempre dentro
     * dos limites.
     *
     * @param  array<string, string>  $clean
     *
     * @throws ValidationException
     * @throws UniqueConstraintViolationException quando outro pedido criou a mesma linha entretanto
     */
    private function writeLine(Cart $cart, Variant $variant, int $qty, array $clean, string $hash): void
    {
        $existing = $cart->items()
            ->where('variant_id', $variant->id)
            ->where('personalization_hash', $hash)
            ->first();

        if ($existing !== null) {
            $this->assertBuyable($variant, $existing->qty + $qty);
            $existing->update(['qty' => $existing->qty + $qty]);

            return;
        }

        $maxLines = (int) config('shop.cart.max_lines');

        if ($cart->items()->count() >= $maxLines) {
            throw ValidationException::withMessages([
                'cart' => trans('store.cart.errors.max_lines', ['max' => $maxLines]),
            ]);
        }

        $this->assertBuyable($variant, $qty);

        $cart->items()->create([
            'variant_id' => $variant->id,
            'qty' => $qty,
            'personalization' => $clean === [] ? null : $clean,
            'personalization_hash' => $hash,
        ]);
    }

    private function assertOwns(Cart $cart, CartItem $item): void
    {
        if ((int) $item->cart_id !== (int) $cart->id) {
            throw (new ModelNotFoundException)->setModel(CartItem::class, [$item->id]);
        }
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
            Buyable::BAD_QTY => trans('store.cart.errors.qty_range', ['max' => (int) config('shop.cart.max_qty')]),
            Buyable::OVER_STOCK => trans('store.cart.errors.over_stock', ['max' => (int) $check->maxQty]),
            Buyable::SOLD_OUT => trans('store.cart.errors.sold_out'),
            default => trans('store.cart.errors.not_buyable'),
        };

        throw ValidationException::withMessages(['qty' => $message]);
    }
}
