<?php

namespace App\Http\Requests\Product;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
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
     * "24,90" vindo de outro lado — a mesma normalizacao do
     * StoreVariantRequest, para o `numeric` passar e o Money::fromDecimal
     * receber sempre a mesma forma.
     */
    protected function prepareForValidation(): void
    {
        $price = $this->input('variants.price');

        if (is_string($price) && $price !== '') {
            $this->merge([
                'variants' => [
                    ...(array) $this->input('variants'),
                    'price' => str_replace(',', '.', $price),
                ],
            ]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            // Vazio = gerado a partir do nome (ProductService). Quando vem
            // preenchido, a unicidade e garantida la com sufixo numerico —
            // aqui so se guarda a forma.
            'slug' => ['nullable', 'string', 'max:140', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'description' => ['nullable', 'string', 'max:10000'],
            'tags' => ['array', 'max:20'],
            'tags.*' => ['string', 'max:60'],
            'status' => ['required', Rule::in(Product::STATUSES)],
            'featured' => ['boolean'],
            'vat_rate' => ['required', 'integer', 'min:0', 'max:100'],
            'fulfillment_mode' => ['required', Rule::in(Product::FULFILLMENT_MODES)],
            'production_time_days' => ['nullable', 'integer', 'min:0', 'max:60'],
            'allow_backorder' => ['boolean'],
            'max_open_production_qty' => ['nullable', 'integer', 'min:1', 'max:65535'],
            ...$this->variantRules(),
        ];
    }

    /**
     * Matriz de variantes do modal de novo produto: as cores e os tamanhos
     * escolhidos, mais o molde (preco, gramagem, tempo) aplicado a todas as
     * combinacoes. Ausente = produto sem variantes, que e o que um rascunho e.
     *
     * O `required_with` no preco e o que impede uma matriz sem preco: as
     * variantes nasceriam todas a zero euros e vendaveis.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function variantRules(): array
    {
        return [
            'variants' => ['nullable', 'array'],
            'variants.color_ids' => ['array', 'max:60'],
            'variants.color_ids.*' => ['integer', Rule::exists('colors', 'id')],
            'variants.sizes' => ['array', 'max:10'],
            'variants.sizes.*' => ['string', 'max:60'],
            'variants.price' => ['required_with:variants.color_ids', 'nullable', 'numeric', 'min:0', 'max:99999.99'],
            'variants.filament_weight_grams' => ['nullable', 'integer', 'min:0', 'max:99999'],
            'variants.printing_time_minutes' => ['nullable', 'integer', 'min:0', 'max:99999'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.regex' => 'O endereço só pode ter minúsculas, números e hífens (ex.: vaso-ondulado).',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'slug' => 'endereço',
            'category_id' => 'categoria',
            'vat_rate' => 'IVA',
            'tags' => 'etiquetas',
            'variants.color_ids' => 'cores',
            'variants.sizes' => 'tamanhos',
            'variants.price' => 'preço de venda',
            'variants.filament_weight_grams' => 'gramagem',
            'variants.printing_time_minutes' => 'tempo de impressão',
        ];
    }
}
