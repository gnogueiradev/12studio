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
        foreach (['normal_price', 'sale_price', 'wholesale_price'] as $field) {
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
            'color_id' => ['nullable', 'integer', Rule::exists('colors', 'id')],
            'size_label' => ['nullable', 'string', 'max:60'],
            'normal_price' => ['required', 'numeric', 'min:0', 'max:99999.99'],
            'sale_price' => ['nullable', 'numeric', 'min:0', 'max:99999.99'],
            'wholesale_price' => ['nullable', 'numeric', 'min:0', 'max:99999.99'],
            'filament_weight_grams' => ['nullable', 'integer', 'min:0', 'max:99999'],
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
                $normal = (float) $this->input('normal_price');
                $sale = $this->filled('sale_price') ? (float) $this->input('sale_price') : null;

                // Uma "promocao" igual ou acima do preco normal riscaria na
                // montra um valor mais baixo do que o pedido — anuncio de
                // aumento em vez de desconto.
                if ($sale !== null && $sale >= $normal) {
                    $validator->errors()->add(
                        'sale_price',
                        'O preço promocional tem de ser inferior ao preço normal.',
                    );
                }

                // O preco de revenda existe para vender mais barato a quem
                // revende; acima do que o cliente final paga nao e revenda.
                if ($this->filled('wholesale_price') && (float) $this->input('wholesale_price') > ($sale ?? $normal)) {
                    $validator->errors()->add(
                        'wholesale_price',
                        'O preço de revenda não pode ser superior ao preço de venda.',
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
            'color_id' => 'cor',
            'normal_price' => 'preço normal',
            'sale_price' => 'preço promocional',
            'wholesale_price' => 'preço de revenda',
            'filament_weight_grams' => 'gramagem',
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
