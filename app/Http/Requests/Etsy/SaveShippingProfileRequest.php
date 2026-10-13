<?php

namespace App\Http\Requests\Etsy;

use App\Http\Requests\AdminFormRequest;
use App\Http\Requests\Etsy\Concerns\ValidatesShippingFields;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Criar ou editar um perfil de envio da Etsy.
 *
 * Sao dois pedidos diferentes com o mesmo nome. Um perfil NOVO nasce com o
 * primeiro destino, e por isso traz custos e prazo. EDITAR um perfil so muda
 * o titulo e a origem — na Etsy os custos vivem nos destinos — e o resto,
 * mesmo que venha, nem e validado: nao vai a lado nenhum.
 */
class SaveShippingProfileRequest extends AdminFormRequest
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
        $identity = [
            'shipping_profile_id' => ['nullable', 'integer', 'min:1'],
            'title' => ['required', 'string', 'max:255'],
            'origin_country_iso' => ['required', 'string', 'size:2', 'alpha'],
            'origin_postal_code' => ['nullable', 'string', 'max:20'],
        ];

        if ($this->isUpdate()) {
            return $identity;
        }

        return [
            ...$identity,
            'primary_cost' => $this->moneyRules(required: true),
            'secondary_cost' => $this->moneyRules(required: true),
            'destination_country_iso' => ['nullable', 'string', 'size:2', 'alpha'],
            'destination_region' => ['nullable', Rule::in(['eu', 'non_eu', 'none'])],
            ...$this->deliveryRules(),
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        if ($this->isUpdate()) {
            return [];
        }

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
            'primary_cost.required' => 'Um perfil novo precisa do custo de envio do primeiro destino.',
            'secondary_cost.required' => 'Indica o custo por cada artigo adicional (0 se não houver).',
            'origin_country_iso.size' => 'O país de origem é o código de duas letras (ex. PT).',
        ];
    }

    private function isUpdate(): bool
    {
        return filled($this->input('shipping_profile_id'));
    }
}
