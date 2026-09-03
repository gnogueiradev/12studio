<?php

namespace App\Http\Requests\Variant;

use App\Http\Requests\AdminFormRequest;

/**
 * A aba "Producao" do produto: o tempo de impressao e a gramagem do PRODUTO,
 * a escrever de uma vez em todas as variantes.
 *
 * Um par de valores e nao um por variante, porque a cor e o material mudam
 * entre variantes mas o tempo de maquina e o plastico gasto nao — a peca e a
 * mesma. O que faz o preco diferir entre elas e o preco/kg de cada material,
 * e isso ja vive na variante.
 *
 * As horas e os minutos chegam separados pela mesma razao do
 * PricingPreviewRequest — "1,30" tanto se le como uma hora e trinta como 1,3
 * horas — e os tectos sao os do StoreVariantRequest, que guarda os mesmos dois
 * campos uma variante de cada vez.
 *
 * Os tres campos sao nullable: um campo vazio LIMPA o valor em todas as
 * variantes, e e a unica maneira de desfazer um engano sem abrir ficha a
 * ficha. Zero e outra coisa — uma peca de zero gramas nao existe, e o calculo
 * recusa-a.
 */
class UpdateVariantProductionRequest extends AdminFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'hours' => ['nullable', 'integer', 'min:0', 'max:999'],
            'minutes' => ['nullable', 'integer', 'min:0', 'max:59'],
            'weight_grams' => ['nullable', 'integer', 'min:0', 'max:99999'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'hours' => 'horas',
            'minutes' => 'minutos',
            'weight_grams' => 'gramagem',
        ];
    }

    /**
     * Ja traduzido para o que a base de dados guarda: minutos totais e gramas,
     * ou null quando o campo veio vazio.
     *
     * @return array{printing_time_minutes: int|null, filament_weight_grams: int|null}
     */
    public function values(): array
    {
        $hours = $this->validated('hours');
        $minutes = $this->validated('minutes');

        // Horas E minutos vazios = sem tempo. Um dos dois preenchido chega:
        // "0 h 45 min" e "2 h" sao ambos tempos.
        $totalMinutes = $hours === null && $minutes === null
            ? null
            : (int) $hours * 60 + (int) $minutes;

        $grams = $this->validated('weight_grams');

        return [
            'printing_time_minutes' => $totalMinutes,
            'filament_weight_grams' => $grams === null ? null : (int) $grams,
        ];
    }
}
