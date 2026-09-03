<?php

namespace App\Http\Requests\Variant;

use App\Http\Requests\AdminFormRequest;
use App\Models\Product;
use Illuminate\Validation\Rule;

/**
 * A aba "Producao" do produto: tempo de impressao e gramagem de todas as
 * variantes de uma vez.
 *
 * As horas e os minutos chegam separados pela mesma razao do
 * PricingPreviewRequest — "1,30" tanto se le como uma hora e trinta como 1,3
 * horas — e os tectos sao os do StoreVariantRequest, que guarda os mesmos dois
 * campos uma variante de cada vez.
 *
 * Os tres campos sao nullable: uma linha vazia LIMPA o valor, e e a unica
 * maneira de desfazer um engano sem abrir a ficha da variante. Zero e outra
 * coisa — uma peca de zero gramas nao existe, e o calculo recusa-a.
 */
class UpdateVariantProductionRequest extends AdminFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var Product $product */
        $product = $this->route('product');

        return [
            'rows' => ['required', 'array'],
            // Uma variante de OUTRO produto nao passa: o URL diz a que produto
            // pertence a aba, e um id fora dele era escrever no catalogo de
            // outra ficha por engano — ou por malicia.
            'rows.*.id' => ['required', 'integer', Rule::exists('variants', 'id')->where('product_id', $product->id)],
            'rows.*.hours' => ['nullable', 'integer', 'min:0', 'max:999'],
            'rows.*.minutes' => ['nullable', 'integer', 'min:0', 'max:59'],
            'rows.*.weight_grams' => ['nullable', 'integer', 'min:0', 'max:99999'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'rows.*.id' => 'variante',
            'rows.*.hours' => 'horas',
            'rows.*.minutes' => 'minutos',
            'rows.*.weight_grams' => 'gramagem',
        ];
    }

    /**
     * As linhas ja traduzidas para o que a base de dados guarda: minutos
     * totais e gramas, ou null quando a linha veio vazia.
     *
     * Indexado pelo id da variante.
     *
     * @return array<int, array{printing_time_minutes: int|null, filament_weight_grams: int|null}>
     */
    public function rows(): array
    {
        $rows = [];

        /** @var array<int, array{id: int|string, hours?: mixed, minutes?: mixed, weight_grams?: mixed}> $validated */
        $validated = $this->validated('rows');

        foreach ($validated as $row) {
            $hours = $row['hours'] ?? null;
            $minutes = $row['minutes'] ?? null;

            // Horas E minutos vazios = sem tempo. Um dos dois preenchido chega:
            // "0 h 45 min" e "2 h" sao ambos tempos.
            $totalMinutes = $hours === null && $minutes === null
                ? null
                : (int) $hours * 60 + (int) $minutes;

            $grams = $row['weight_grams'] ?? null;

            $rows[(int) $row['id']] = [
                'printing_time_minutes' => $totalMinutes,
                'filament_weight_grams' => $grams === null ? null : (int) $grams,
            ];
        }

        return $rows;
    }
}
