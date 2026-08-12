<?php

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Um rascunho guarda-se a meio: sem email, sem artigos, com o codigo postal
 * ainda por acabar. Por isso nada aqui e `required` e nao ha o `after()` do
 * StoreManualOrderRequest — ficam so os limites de tamanho, para nada absurdo
 * entrar na base de dados.
 *
 * A validacao a serio acontece quando o rascunho vira encomenda, no
 * StoreManualOrderRequest, que continua intocado.
 */
class StoreOrderDraftRequest extends FormRequest
{
    /**
     * Segunda camada de defesa por cima do middleware 'admin'.
     */
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['nullable', 'integer'],
            'customer_name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'nif' => ['nullable', 'string', 'max:20'],

            'sales_channel' => ['nullable', 'string', 'max:20'],
            'external_order_reference' => ['nullable', 'string', 'max:100'],
            'payment_method' => ['nullable', 'string', 'max:20'],
            'payment_status' => ['nullable', 'string', 'max:20'],

            'shipping_price' => ['nullable', 'string', 'max:20'],
            'shipping_method_name' => ['nullable', 'string', 'max:120'],

            'line1' => ['nullable', 'string', 'max:190'],
            'line2' => ['nullable', 'string', 'max:190'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:80'],
            'country' => ['nullable', 'string', 'max:2'],

            'admin_note' => ['nullable', 'string', 'max:2000'],
            'send_confirmation' => ['boolean'],

            'items' => ['array', 'max:100'],
            'items.*.variant_id' => ['nullable', 'integer'],
            'items.*.product_name' => ['nullable', 'string', 'max:120'],
            'items.*.variant_label' => ['nullable', 'string', 'max:120'],
            'items.*.sku' => ['nullable', 'string', 'max:60'],
            'items.*.unit_price' => ['nullable', 'string', 'max:20'],
            'items.*.qty' => ['nullable', 'integer', 'min:1', 'max:999'],
            'items.*.price_override_reason' => ['nullable', 'string', 'max:200'],
            'items.*.fulfillment_mode' => ['nullable', 'string', 'max:20'],
            'items.*.vat_rate' => ['nullable', 'integer', 'min:0', 'max:100'],
        ];
    }
}
