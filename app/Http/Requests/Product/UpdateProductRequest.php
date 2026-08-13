<?php

namespace App\Http\Requests\Product;

use Illuminate\Support\Arr;

class UpdateProductRequest extends StoreProductRequest
{
    // Mesmas regras e autorizacao do store — o slug e derivado do nome no
    // ProductService, nunca aceite do request.

    /**
     * Menos a matriz de variantes e menos as fotografias — as duas coisas que
     * so existem na criacao. A partir dai, as variantes editam-se uma a uma e
     * a galeria tem endpoints proprios (ProductImageController), porque um
     * produto ja gravado pode receber, reordenar e apagar fotos sem que isso
     * seja um `update` ao produto.
     *
     * Deixa-las passar aqui era um `update` a criar variantes novas e a
     * acumular imagens de cada vez que se corrigisse o nome do produto.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return Arr::except(parent::rules(), [
            ...array_keys($this->variantRules()),
            ...array_keys($this->imageRules()),
        ]);
    }
}
