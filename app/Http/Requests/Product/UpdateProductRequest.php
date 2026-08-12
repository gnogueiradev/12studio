<?php

namespace App\Http\Requests\Product;

use Illuminate\Support\Arr;

class UpdateProductRequest extends StoreProductRequest
{
    // Mesmas regras e autorizacao do store — o slug e derivado do nome no
    // ProductService, nunca aceite do request.

    /**
     * Menos a matriz de variantes, que so existe na criacao: a partir dai as
     * variantes editam-se uma a uma, na seccao propria da pagina de edicao.
     * Deixa-la passar aqui era um `update` a criar variantes novas de cada vez
     * que se corrigisse o nome do produto.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return Arr::except(parent::rules(), array_keys($this->variantRules()));
    }
}
