<?php

namespace App\Http\Requests\Etsy;

use App\Http\Requests\AdminFormRequest;
use Illuminate\Validation\Rule;

/**
 * Um perfil de processamento da Etsy: quanto tempo leva uma peca a sair,
 * conforme esteja pronta na prateleira ou seja feita por encomenda.
 */
class SaveProcessingProfileRequest extends AdminFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'definition_id' => ['nullable', 'integer', 'min:1'],
            'readiness_state' => ['required', Rule::in(['ready_to_ship', 'made_to_order'])],
            'min_processing_time' => ['required', 'integer', 'min:1', 'max:70'],
            'max_processing_time' => ['required', 'integer', 'min:1', 'max:70', 'gte:min_processing_time'],
            'processing_time_unit' => ['required', Rule::in(['days', 'weeks'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'readiness_state.in' => 'O tipo é "ready_to_ship" (pronto a enviar) ou "made_to_order" (feito por encomenda).',
            'max_processing_time.gte' => 'O tempo máximo não pode ser menor do que o mínimo.',
            'processing_time_unit.in' => 'A unidade é "days" ou "weeks".',
        ];
    }
}
