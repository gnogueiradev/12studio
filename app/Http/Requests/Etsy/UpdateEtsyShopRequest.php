<?php

namespace App\Http\Requests\Etsy;

use App\Etsy\EtsyShop;
use App\Http\Requests\AdminFormRequest;
use Illuminate\Validation\Validator;

/**
 * Os textos publicos da loja na Etsy. So mudam os que vierem no pedido; um
 * texto vazio limpa-o la.
 */
class UpdateEtsyShopRequest extends AdminFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // 55 e o limite da Etsy para o titulo da loja.
            'title' => ['sometimes', 'nullable', 'string', 'max:55'],
            'announcement' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'sale_message' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'digital_sale_message' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'policy_additional' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (array_intersect(EtsyShop::TEXT_FIELDS, array_keys($this->all())) === []) {
                    $validator->errors()->add('title', 'Indica pelo menos um texto para mudar.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.max' => 'O título da loja tem no máximo 55 caracteres.',
        ];
    }
}
