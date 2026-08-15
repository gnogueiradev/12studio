<?php

namespace App\Http\Requests\Setting;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Os parametros da calculadora de precos, em unidades humanas.
 *
 * O formulario fala em percentagem, euros e "1,75"; a conversao para pontos
 * base e centimos e do PricingSettings::fromForm(). Aqui so se garante que os
 * numeros fazem sentido.
 */
class UpdatePricingSettingsRequest extends FormRequest
{
    /**
     * Segunda camada de defesa por cima do middleware 'admin' — middleware
     * sozinho nao e seguranca (padrao do plano).
     */
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * Virgula -> ponto em todos os decimais. Mesmo remendo do
     * StoreMaterialRequest: quem escreve precos escreve "0,15", nao "0.15".
     */
    protected function prepareForValidation(): void
    {
        foreach ([
            'failure_reserve_percent',
            'minimum_resale_price',
            'resale_multiplier',
            'retail_multiplier',
            'minimum_retail_multiplier',
            'handling_cost',
            'batch_job_handling',
            'batch_unit_handling',
        ] as $field) {
            $value = $this->input($field);

            if (is_string($value) && $value !== '') {
                $this->merge([$field => str_replace(',', '.', $value)]);
            }
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Tecto de 50%: uma reserva acima disso quer dizer que o problema e
            // a impressora, nao o preco.
            'failure_reserve_percent' => ['required', 'numeric', 'min:0', 'max:50'],
            'minimum_resale_price' => ['required', 'numeric', 'min:0', 'max:999.99'],

            /*
             * Multiplicadores nunca abaixo de 1,00x. Nao e so bom senso
             * comercial: com um multiplicador menor que 1 o lucro fica negativo
             * e o Micros::divRound sai do dominio nao negativo que documenta.
             */
            'resale_multiplier' => ['required', 'numeric', 'min:1', 'max:10'],
            'retail_multiplier' => ['required', 'numeric', 'min:1', 'max:10'],
            'minimum_retail_multiplier' => ['required', 'numeric', 'min:1', 'max:10'],

            'handling_cost' => ['required', 'numeric', 'min:0', 'max:99.99'],
            'batch_job_handling' => ['required', 'numeric', 'min:0', 'max:99.99'],
            'batch_unit_handling' => ['required', 'numeric', 'min:0', 'max:99.99'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            $this->validateMultiplierOrder(...),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'failure_reserve_percent' => 'reserva para falhas',
            'minimum_resale_price' => 'preço mínimo de revenda',
            'resale_multiplier' => 'multiplicador de revenda',
            'retail_multiplier' => 'multiplicador do cliente final',
            'minimum_retail_multiplier' => 'multiplicador mínimo do revendedor',
            'handling_cost' => 'custo de manuseamento',
            'batch_job_handling' => 'manuseamento por impressão',
            'batch_unit_handling' => 'manuseamento por unidade',
        ];
    }

    /**
     * O minimo do revendedor tem de caber por baixo do multiplicador normal.
     * Ao contrario: o arredondamento comercial deixava de ter efeito nenhum e
     * TODOS os precos passavam pela rede de seguranca.
     */
    private function validateMultiplierOrder(Validator $validator): void
    {
        $retail = $this->input('retail_multiplier');
        $minimum = $this->input('minimum_retail_multiplier');

        if (! is_numeric($retail) || ! is_numeric($minimum)) {
            return;
        }

        if ((float) $minimum > (float) $retail) {
            $validator->errors()->add(
                'minimum_retail_multiplier',
                'O mínimo do revendedor não pode ser maior do que o multiplicador do cliente final.',
            );
        }
    }
}
