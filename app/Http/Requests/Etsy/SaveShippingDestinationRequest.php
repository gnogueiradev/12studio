<?php

namespace App\Http\Requests\Etsy;

use App\Http\Requests\AdminFormRequest;
use App\Http\Requests\Etsy\Concerns\ValidatesShippingFields;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Um destino de um perfil de envio: para onde, por quanto e em quanto tempo.
 * E aqui que vivem os custos de envio na Etsy.
 */
class SaveShippingDestinationRequest extends AdminFormRequest
{
    use ValidatesShippingFields;

    protected function prepareForValidation(): void
    {
        $this->normalizeMoney(['primary_cost', 'secondary_cost']);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'destination_id' => ['nullable', 'integer', 'min:1'],
            'destination_country_iso' => ['nullable', 'string', 'size:2', 'alpha'],
            'destination_region' => ['nullable', Rule::in(['eu', 'non_eu', 'none'])],
            'primary_cost' => $this->moneyRules(required: true),
            'secondary_cost' => $this->moneyRules(required: true),
            ...$this->deliveryRules(),
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $this->requireOneDestination($validator);
                $this->requireDelivery($validator);
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'primary_cost.required' => 'Indica o custo de envio.',
            'secondary_cost.required' => 'Indica o custo por cada artigo adicional (0 se não houver).',
            'destination_country_iso.size' => 'O país é o código de duas letras (ex. PT, ES, FR).',
        ];
    }
}
