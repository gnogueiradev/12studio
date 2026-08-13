<?php

namespace App\Http\Requests\Setting;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Validator;

/**
 * Os parametros da calculadora de precos, em unidades humanas.
 *
 * O formulario fala em percentagem, euros e "1,75"; a conversao para pontos
 * base e centimos e do PricingSettings::fromForm(). Aqui so se garante que os
 * numeros fazem sentido — e que as duas tabelas de faixas continuam a ser
 * tabelas de faixas, porque uma delas mal ordenada da precos silenciosamente
 * errados em vez de um erro.
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
     * Virgula -> ponto em todos os decimais, incluindo os de dentro das duas
     * tabelas. Mesmo remendo do StoreMaterialRequest, aqui aplicado a arvore.
     */
    protected function prepareForValidation(): void
    {
        foreach ([
            'failure_reserve_percent',
            'minimum_resale_price',
            'retail_multiplier',
            'minimum_retail_multiplier',
            'batch_job_handling',
            'batch_unit_handling',
        ] as $field) {
            $value = $this->input($field);

            if (is_string($value) && $value !== '') {
                $this->merge([$field => str_replace(',', '.', $value)]);
            }
        }

        foreach (['resale_multipliers', 'handling_tiers'] as $table) {
            $rows = $this->input($table);

            if (is_array($rows)) {
                $this->merge([$table => array_map($this->commaToDot(...), $rows)]);
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
            'retail_multiplier' => ['required', 'numeric', 'min:1', 'max:10'],
            'minimum_retail_multiplier' => ['required', 'numeric', 'min:1', 'max:10'],

            'batch_job_handling' => ['required', 'numeric', 'min:0', 'max:99.99'],
            'batch_unit_handling' => ['required', 'numeric', 'min:0', 'max:99.99'],

            'resale_multipliers' => ['required', 'array', 'min:2', 'max:8'],
            'resale_multipliers.*.up_to' => ['nullable', 'numeric', 'min:0.01', 'max:9999.99'],
            'resale_multipliers.*.value' => ['required', 'numeric', 'min:1', 'max:10'],

            'handling_tiers' => ['required', 'array', 'min:2', 'max:8'],
            'handling_tiers.*.up_to_grams' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'handling_tiers.*.value' => ['required', 'numeric', 'min:0', 'max:99.99'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->validateTable($validator, 'resale_multipliers', 'up_to'),
            fn (Validator $validator) => $this->validateTable($validator, 'handling_tiers', 'up_to_grams'),
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
            'retail_multiplier' => 'multiplicador do cliente final',
            'minimum_retail_multiplier' => 'multiplicador mínimo do revendedor',
            'batch_job_handling' => 'manuseamento por impressão',
            'batch_unit_handling' => 'manuseamento por unidade',
            'resale_multipliers' => 'multiplicadores de revenda',
            'handling_tiers' => 'faixas de manuseamento',
        ];
    }

    /**
     * Uma tabela de faixas so funciona se for lida de cima para baixo: limiares
     * a subir, e a ultima aberta.
     *
     * Sem a ordem, a primeira faixa apanhava tudo e as outras eram codigo morto.
     * Sem a faixa aberta no fim, uma peca acima do maior limiar nao encontrava
     * faixa nenhuma — e o preco saia sem manuseamento, sem erro nenhum a dize-lo.
     */
    private function validateTable(Validator $validator, string $table, string $thresholdKey): void
    {
        $rows = $this->input($table);

        if (! is_array($rows) || $rows === []) {
            return;
        }

        $rows = array_values($rows);
        $last = count($rows) - 1;
        $previous = null;

        foreach ($rows as $index => $row) {
            $threshold = Arr::get($row, $thresholdKey);
            $isOpen = $threshold === null || $threshold === '';

            if ($isOpen && $index !== $last) {
                $validator->errors()->add(
                    "{$table}.{$index}.{$thresholdKey}",
                    'Só a última faixa pode ficar sem limite.',
                );

                continue;
            }

            if ($index === $last) {
                if (! $isOpen) {
                    $validator->errors()->add(
                        "{$table}.{$index}.{$thresholdKey}",
                        'A última faixa tem de ficar sem limite, para apanhar tudo o que passa das anteriores.',
                    );
                }

                continue;
            }

            $value = (float) $threshold;

            if ($previous !== null && $value <= $previous) {
                $validator->errors()->add(
                    "{$table}.{$index}.{$thresholdKey}",
                    'Os limites têm de ir sempre a subir.',
                );
            }

            $previous = $value;
        }
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

    private function commaToDot(mixed $row): mixed
    {
        if (! is_array($row) || ! isset($row['value']) || ! is_string($row['value'])) {
            return $row;
        }

        return [...$row, 'value' => str_replace(',', '.', $row['value'])];
    }
}
