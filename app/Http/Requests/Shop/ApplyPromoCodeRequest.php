<?php

namespace App\Http\Requests\Shop;

use Illuminate\Foundation\Http\FormRequest;

/**
 * O código de desconto que o cliente escreveu. Se existe e se vale é do
 * CartQuoter, que o confere de cada vez que conta o carrinho.
 */
class ApplyPromoCodeRequest extends FormRequest
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
            'promo_code' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'promo_code' => trans('store.attributes.promo_code'),
        ];
    }
}
