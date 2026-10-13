<?php

namespace App\Http\Requests\Etsy;

use App\Http\Requests\AdminFormRequest;
use App\Http\Requests\Etsy\Concerns\ValidatesShippingFields;
use Illuminate\Validation\Rule;

/**
 * Um upgrade de envio: a opcao mais rapida (e mais cara) que o comprador pode
 * escolher no checkout da Etsy.
 */
class SaveShippingUpgradeRequest extends AdminFormRequest
{
    use ValidatesShippingFields;

    protected function prepareForValidation(): void
    {
        $this->normalizeMoney(['price', 'secondary_price']);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'upgrade_id' => ['nullable', 'integer', 'min:1'],
            'upgrade_name' => ['required', 'string', 'max:100'],
            // A Etsy quer 0 ou 1; aqui escreve-se o que quer dizer.
            'type' => ['required', Rule::in(['domestic', 'international'])],
            'price' => $this->moneyRules(required: true),
            'secondary_price' => $this->moneyRules(required: true),
            ...$this->deliveryRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.in' => 'O tipo é "domestic" (nacional) ou "international" (internacional).',
            'price.required' => 'Indica o preço do upgrade.',
            'secondary_price.required' => 'Indica o preço por cada artigo adicional (0 se não houver).',
        ];
    }
}
