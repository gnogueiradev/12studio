<?php

namespace App\Http\Requests\Etsy;

use App\Http\Requests\AdminFormRequest;

/**
 * As predefinicoes dos envios: que perfis leva um anuncio que nao escolheu
 * nenhum. Sao escolhas nossas — gravam-se ca e nao escrevem nada na Etsy.
 */
class SaveEtsyDefaultsRequest extends AdminFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'default_shipping_profile_id' => ['nullable', 'integer', 'min:1'],
            'ready_to_ship_profile_id' => ['nullable', 'integer', 'min:1'],
            'made_to_order_profile_id' => ['nullable', 'integer', 'min:1'],
            'default_return_policy_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
