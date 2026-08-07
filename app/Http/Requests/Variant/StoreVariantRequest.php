<?php

namespace App\Http\Requests\Variant;

use App\Models\Variant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreVariantRequest extends FormRequest
{
    /**
     * Segunda camada de defesa por cima do middleware 'admin'.
     */
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * O input `type=number` envia sempre ponto, mas o admin tambem cola
     * "24,90" vindo de outro lado. Normalizar aqui deixa o `numeric` passar
     * e o Money::fromDecimal receber sempre a mesma forma.
     */
    protected function prepareForValidation(): void
    {
        foreach (['price', 'compare_at_price'] as $field) {
            $value = $this->input($field);

            if (is_string($value) && $value !== '') {
                $this->merge([$field => str_replace(',', '.', $value)]);
            }
        }
    }

    /**
     * Precos chegam em euros ("12,50") e sao convertidos para centimos no
     * VariantService — nunca ha floats a atravessar a aplicacao.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'sku' => ['required', 'string', 'max:60', Rule::unique('variants', 'sku')->ignore($this->variantId())],
            'size_label' => ['nullable', 'string', 'max:60'],
            'price' => ['required', 'numeric', 'min:0', 'max:99999.99'],
            'compare_at_price' => ['nullable', 'numeric', 'min:0', 'max:99999.99'],
            'stock' => ['required', 'integer', 'min:0', 'max:99999'],
            'low_stock_threshold' => ['required', 'integer', 'min:0', 'max:9999'],
            'is_default' => ['boolean'],
            'active' => ['boolean'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $compareAt = $this->input('compare_at_price');

                // "Antes 24,90 €" so faz sentido acima do preco atual;
                // abaixo passaria a montra a anunciar um aumento.
                if ($compareAt !== null && $compareAt !== '' && (float) $compareAt <= (float) $this->input('price')) {
                    $validator->errors()->add(
                        'compare_at_price',
                        'O preço anterior tem de ser superior ao preço atual.',
                    );
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'sku' => 'SKU',
            'price' => 'preço',
            'compare_at_price' => 'preço anterior',
            'size_label' => 'tamanho',
            'low_stock_threshold' => 'limiar de stock baixo',
        ];
    }

    /**
     * Null no store; o id da variante em edicao no update (rota shallow).
     */
    protected function variantId(): ?int
    {
        $variant = $this->route('variant');

        return $variant instanceof Variant ? $variant->id : null;
    }
}
