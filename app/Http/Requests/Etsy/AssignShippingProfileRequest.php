<?php

namespace App\Http\Requests\Etsy;

use App\Http\Requests\AdminFormRequest;
use Illuminate\Validation\Rule;

/**
 * Dar um perfil de envio a um ou a varios produtos de uma vez.
 */
class AssignShippingProfileRequest extends AdminFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'product_ids' => ['required', 'array', 'min:1', 'max:100'],
            // Sem os apagados: a regra `exists` nao conhece o scope, por isso
            // di-lo aqui.
            'product_ids.*' => ['integer', 'distinct', Rule::exists('products', 'id')->whereNull('deleted_at')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'product_ids.required' => 'Escolhe pelo menos um produto.',
            'product_ids.*.exists' => 'Um dos produtos escolhidos não existe (ou foi apagado).',
        ];
    }
}
