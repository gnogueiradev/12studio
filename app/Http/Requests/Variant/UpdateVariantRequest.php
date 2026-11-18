<?php

namespace App\Http\Requests\Variant;

class UpdateVariantRequest extends StoreVariantRequest
{
    // As regras sao as do store, com uma excecao. A unicidade do SKU ja ignora
    // a propria variante via StoreVariantRequest::variantId().

    /**
     * O stock deixa de ser obrigatorio: o formulario so o manda quando o admin
     * mexeu mesmo no campo. Obrigar a mandar a linha inteira repunha o stock
     * com que a pagina abriu por cima do que entretanto se vendeu.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'stock' => ['sometimes', 'integer', 'min:0', 'max:99999'],
        ];
    }
}
