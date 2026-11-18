<?php

namespace App\Http\Requests\Shop;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Pôr uma variante no carrinho. Só o que o cliente escolhe (qual, quantas, a
 * personalização): preços e totais nunca vêm do pedido, saem da base de dados.
 */
class AddToCartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'variant_id' => ['required', 'integer', 'exists:variants,id'],
            'qty' => ['required', 'integer', 'min:1', 'max:'.(int) config('shop.cart.max_qty')],
            'personalization' => ['nullable', 'array'],
            // Só valores simples: o CartService limpa e confere cada um contra
            // os campos do produto.
            'personalization.*' => ['nullable', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_scalar($value)) {
                    $fail(trans('validation.string', ['attribute' => $attribute]));
                }
            }],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'qty' => trans('store.attributes.quantity'),
        ];
    }
}
