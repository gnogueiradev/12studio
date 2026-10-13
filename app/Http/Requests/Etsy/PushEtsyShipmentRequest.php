<?php

namespace App\Http\Requests\Etsy;

use App\Http\Requests\AdminFormRequest;

/**
 * Mandar (outra vez) o envio de uma encomenda para a Etsy, a partir da pagina
 * da encomenda. A unica coisa que se escreve e a nota para o comprador; o
 * seguimento e o que estiver gravado na encomenda.
 */
class PushEtsyShipmentRequest extends AdminFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'note_to_buyer' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['note_to_buyer' => 'nota para o comprador'];
    }
}
