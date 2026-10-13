<?php

namespace App\Http\Requests\Etsy;

use App\Http\Requests\AdminFormRequest;
use App\Models\Product;
use Illuminate\Validation\Rule;

/**
 * O conteudo proprio de um produto na Etsy: o que o anuncio diz de diferente
 * do site, o preco Etsy de cada variante e as fotografias que nao vao.
 *
 * Guarda-se ca e nao envia nada. Os limites sao os da Etsy (config/etsy.php):
 * mais vale ouvi-los aqui, em portugues, do que da Etsy no momento do envio.
 * Um campo vazio tira o conteudo proprio e volta a valer o do produto.
 */
class SaveEtsyListingRequest extends AdminFormRequest
{
    /**
     * "29,90" -> "29.90" antes das regras, como nos precos das variantes.
     */
    protected function prepareForValidation(): void
    {
        $variants = $this->input('variants');

        if (! is_array($variants)) {
            return;
        }

        foreach ($variants as $index => $row) {
            if (is_array($row) && is_string($row['etsy_price'] ?? null) && trim($row['etsy_price']) !== '') {
                $variants[$index]['etsy_price'] = str_replace(',', '.', trim($row['etsy_price']));
            }
        }

        $this->merge(['variants' => $variants]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $limits = (array) config('etsy.listing');
        $id = ['nullable', 'integer', 'min:1'];

        /** @var Product|null $product */
        $product = $this->route('product');
        $productId = $product?->getKey() ?? 0;

        return [
            'title' => ['nullable', 'string', 'max:'.$limits['title_max']],
            'description' => ['nullable', 'string', 'max:60000'],
            'tags' => ['nullable', 'array', 'max:'.$limits['tags_max']],
            'tags.*' => ['string', 'distinct:ignore_case', 'max:'.$limits['tag_length_max']],
            'materials' => ['nullable', 'array', 'max:'.$limits['materials_max']],
            'materials.*' => ['string', 'distinct:ignore_case', 'max:45'],
            'taxonomy_id' => $id,
            'who_made' => ['nullable', Rule::in((array) $limits['who_made'])],
            'when_made' => ['nullable', Rule::in((array) $limits['when_made'])],
            'shipping_profile_id' => $id,
            'return_policy_id' => $id,
            'readiness_state_id' => $id,
            'shop_section_id' => $id,

            // Os atributos da categoria (ver etsy_taxonomy).
            'properties' => ['nullable', 'array', 'max:30'],
            'properties.*.property_id' => ['required', 'integer', 'min:1'],
            'properties.*.value_ids' => ['nullable', 'array'],
            'properties.*.value_ids.*' => ['integer', 'min:1'],
            'properties.*.values' => ['nullable', 'array'],
            'properties.*.values.*' => ['string', 'max:100'],
            'properties.*.scale_id' => ['nullable', 'integer', 'min:1'],

            'variants' => ['nullable', 'array'],
            'variants.*.variant_id' => [
                'required', 'integer', 'distinct',
                Rule::exists('variants', 'id')->where('product_id', $productId),
            ],
            // 0,20 e o minimo que a Etsy aceita num preco.
            'variants.*.etsy_price' => ['nullable', 'numeric', 'min:0.2', 'max:99999.99'],
            'variants.*.enabled' => ['nullable', 'boolean'],

            'excluded_image_ids' => ['nullable', 'array'],
            'excluded_image_ids.*' => [
                'integer',
                Rule::exists('product_images', 'id')->where('product_id', $productId),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $limits = (array) config('etsy.listing');

        return [
            'title.max' => "O título da Etsy pode ter até {$limits['title_max']} caracteres.",
            'tags.max' => "A Etsy aceita até {$limits['tags_max']} etiquetas.",
            'tags.*.max' => "Cada etiqueta pode ter até {$limits['tag_length_max']} caracteres.",
            'variants.*.variant_id.exists' => 'Essa variante não é deste produto.',
            'variants.*.etsy_price.min' => 'O preço mínimo na Etsy é 0,20 €.',
            'excluded_image_ids.*.exists' => 'Essa fotografia não é deste produto.',
        ];
    }
}
