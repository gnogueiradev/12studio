<?php

namespace App\Http\Requests\Etsy\Concerns;

use Illuminate\Validation\Validator;

/**
 * O que um destino, um upgrade e o primeiro destino de um perfil novo tem em
 * comum: dinheiro escrito a portuguesa e um prazo de entrega.
 */
trait ValidatesShippingFields
{
    /**
     * "4,50" -> "4.50" antes das regras, como nos precos das variantes.
     *
     * @param  array<int, string>  $fields
     */
    protected function normalizeMoney(array $fields): void
    {
        foreach ($fields as $field) {
            $value = $this->input($field);

            if (is_string($value) && $value !== '') {
                $this->merge([$field => str_replace(',', '.', trim($value))]);
            }
        }
    }

    /**
     * @return array<int, mixed>
     */
    protected function moneyRules(bool $required): array
    {
        return [$required ? 'required' : 'nullable', 'numeric', 'min:0', 'max:9999.99'];
    }

    /**
     * O prazo de entrega: dias minimos e maximos, ou — em alternativa — uma
     * transportadora com a sua classe de correio.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function deliveryRules(): array
    {
        return [
            'min_delivery_days' => ['nullable', 'integer', 'min:1', 'max:45'],
            'max_delivery_days' => ['nullable', 'integer', 'min:1', 'max:45', 'gte:min_delivery_days'],
            'shipping_carrier_id' => ['nullable', 'integer', 'min:1'],
            'mail_class' => ['nullable', 'string', 'max:60'],
        ];
    }

    /**
     * A Etsy exige uma das duas formas completas; meia de cada nao serve.
     */
    protected function requireDelivery(Validator $validator): void
    {
        $days = filled($this->input('min_delivery_days')) && filled($this->input('max_delivery_days'));
        $carrier = filled($this->input('shipping_carrier_id')) && filled($this->input('mail_class'));

        if (! $days && ! $carrier) {
            $validator->errors()->add(
                'min_delivery_days',
                'Indica o prazo de entrega (dias mínimos e máximos) ou a transportadora com a classe de correio.',
            );
        }
    }

    /**
     * Um destino e um pais OU uma regiao, nunca os dois nem nenhum.
     */
    protected function requireOneDestination(Validator $validator): void
    {
        $country = filled($this->input('destination_country_iso'));
        $region = filled($this->input('destination_region'));

        if ($country && $region) {
            $validator->errors()->add('destination_region', 'Escolhe um país ou uma região, não os dois.');
        }

        if (! $country && ! $region) {
            $validator->errors()->add('destination_country_iso', 'Indica o país de destino (ex. PT) ou uma região (eu, non_eu, none).');
        }
    }
}
