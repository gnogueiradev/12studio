<?php

namespace App\Http\Requests\Order;

use App\Models\Order;
use App\Models\Variant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreManualOrderRequest extends FormRequest
{
    /**
     * Segunda camada de defesa por cima do middleware 'admin'.
     */
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    protected function prepareForValidation(): void
    {
        $items = $this->input('items', []);

        if (is_array($items)) {
            foreach ($items as $index => $item) {
                if (isset($item['unit_price']) && is_string($item['unit_price'])) {
                    $items[$index]['unit_price'] = str_replace(',', '.', $item['unit_price']);
                }
            }

            $this->merge(['items' => $items]);
        }

        if (is_string($this->input('shipping_price'))) {
            $this->merge([
                'shipping_price' => str_replace(',', '.', (string) $this->input('shipping_price')),
            ]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Cliente registado e opcional: o canal Instagram e quase sempre
            // gente sem conta. customer_name, esse, e sempre obrigatorio.
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'customer_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'nif' => ['nullable', 'string', 'max:20'],

            'sales_channel' => ['required', Rule::in(Order::SALES_CHANNELS)],
            'external_order_reference' => ['nullable', 'string', 'max:100'],
            'payment_method' => ['required', Rule::in(Order::PAYMENT_METHODS)],
            'payment_status' => ['required', Rule::in(['pending', 'paid'])],

            'shipping_price' => ['required', 'numeric', 'min:0', 'max:9999.99'],
            'shipping_method_name' => ['nullable', 'string', 'max:120'],

            'line1' => ['nullable', 'string', 'max:190'],
            'line2' => ['nullable', 'string', 'max:190'],
            'postal_code' => ['nullable', 'string', 'regex:/^\d{4}-\d{3}$/'],
            'city' => ['nullable', 'string', 'max:80'],
            'country' => ['nullable', 'string', 'size:2'],

            'admin_note' => ['nullable', 'string', 'max:2000'],
            'send_confirmation' => ['boolean'],

            // Rascunho de onde esta encomenda saiu, para o controller o apagar
            // depois de a criar. Sem Rule::exists: um rascunho ja apagado
            // noutro separador nao e motivo para recusar a encomenda.
            'draft_id' => ['nullable', 'integer'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.variant_id' => ['nullable', 'integer', Rule::exists('variants', 'id')],
            'items.*.product_name' => ['nullable', 'string', 'max:120'],
            'items.*.variant_label' => ['nullable', 'string', 'max:120'],
            'items.*.sku' => ['nullable', 'string', 'max:60'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:99999.99'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:999'],
            'items.*.price_override_reason' => ['nullable', 'string', 'max:200'],
            'items.*.fulfillment_mode' => ['nullable', 'string', 'max:20'],
            'items.*.vat_rate' => ['nullable', 'integer', 'min:0', 'max:100'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach ($this->input('items', []) as $index => $item) {
                    $hasVariant = ! empty($item['variant_id']);

                    // Linha livre: sem variante, a descricao e a unica coisa
                    // que sobrevive na fatura e no quadro de producao.
                    if (! $hasVariant && blank($item['product_name'] ?? null)) {
                        $validator->errors()->add(
                            "items.{$index}.product_name",
                            'Escolhe uma variante ou escreve a descrição do artigo.',
                        );
                    }

                    // Preco alterado face ao catalogo exige justificacao —
                    // e o que permite auditar margens mais tarde.
                    if ($hasVariant && blank($item['price_override_reason'] ?? null)) {
                        $variant = Variant::query()->find((int) $item['variant_id']);
                        $unitPriceCents = (int) round(((float) ($item['unit_price'] ?? 0)) * 100);

                        if ($variant !== null && $variant->price_cents !== $unitPriceCents) {
                            $validator->errors()->add(
                                "items.{$index}.price_override_reason",
                                'Indica o motivo do preço diferente do catálogo.',
                            );
                        }
                    }
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
            'items.required' => 'A encomenda precisa de pelo menos um artigo.',
            'items.min' => 'A encomenda precisa de pelo menos um artigo.',
            'postal_code.regex' => 'O código postal tem de ter o formato 1234-567.',
        ];
    }
}
