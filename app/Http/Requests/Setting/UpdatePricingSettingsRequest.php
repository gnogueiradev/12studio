<?php

namespace App\Http\Requests\Setting;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Os parametros da calculadora de precos, em unidades humanas.
 *
 * O formulario fala em percentagem, euros e minutos; a conversao para pontos
 * base, centimos e micros e do PricingSettings::fromForm(). Aqui so se garante
 * que os numeros fazem sentido.
 *
 * Ja aqui viveu uma regra de ORDEM entre dois multiplicadores (o minimo do
 * revendedor nao podia passar o normal). Saiu com eles: as margens sao agora
 * declaradas em vez de derivadas de um multiplicador, e o preco ao cliente
 * arredonda sempre PARA CIMA — a margem do revendedor passou a estar garantida
 * por construcao e nao ha rede de seguranca nenhuma para ordenar.
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
     * Os dois campos de minutos ficam de fora — sao inteiros.
     */
    protected function prepareForValidation(): void
    {
        foreach ([
            'electricity_price',
            'labor_rate',
            'failure_rate_percent',
            'wholesale_margin_percent',
            'reseller_margin_percent',
            'minimum_wholesale_price',
            'channel_fixed_fee',
            'channel_percentage_fee',
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
            // Tecto de 9,9999 EUR/kWh: acima disso e engano de unidade (o preco
            // do MWh escrito no campo do kWh), nao uma tarifa.
            'electricity_price' => ['required', 'numeric', 'min:0', 'max:9.9999'],
            'labor_rate' => ['required', 'numeric', 'min:0', 'max:999.99'],

            // Dez horas de tecto: acima disso ja nao e trabalho ativo numa
            // peca, e o tempo da maquina outra vez a ser contado como humano.
            'active_labor_minutes' => ['required', 'integer', 'min:0', 'max:600'],
            'setup_labor_minutes' => ['required', 'integer', 'min:0', 'max:600'],

            // Tecto de 50%: uma taxa de falhas acima disso quer dizer que o
            // problema e a impressora, nao o preco. E, mais abaixo na formula,
            // o custo divide-se por (1 - taxa) — a 100% era divisao por zero.
            'failure_rate_percent' => ['required', 'numeric', 'min:0', 'max:50'],

            /*
             * Margens nunca a 100%. Nao e so bom senso comercial: o preco sai
             * de custo / (1 - margem), e a 100% isso e uma divisao por zero.
             * O tecto de 95% deixa espaco a qualquer estrategia real e mantem
             * o denominador longe do zero.
             */
            'wholesale_margin_percent' => ['required', 'numeric', 'min:0', 'max:95'],
            'reseller_margin_percent' => ['required', 'numeric', 'min:0', 'max:95'],

            'minimum_wholesale_price' => ['required', 'numeric', 'min:0', 'max:999.99'],

            'channel_fixed_fee' => ['required', 'numeric', 'min:0', 'max:999.99'],
            // Uma comissao acima de metade da venda nao e um canal, e um socio.
            'channel_percentage_fee' => ['required', 'numeric', 'min:0', 'max:50'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'electricity_price' => 'preço da eletricidade',
            'labor_rate' => 'valor do meu trabalho',
            'active_labor_minutes' => 'trabalho ativo por peça',
            'setup_labor_minutes' => 'preparação por impressão',
            'failure_rate_percent' => 'taxa de falhas',
            'wholesale_margin_percent' => 'margem de revenda',
            'reseller_margin_percent' => 'margem do revendedor',
            'minimum_wholesale_price' => 'preço mínimo de revenda',
            'channel_fixed_fee' => 'taxa fixa do canal',
            'channel_percentage_fee' => 'comissão do canal',
        ];
    }
}
