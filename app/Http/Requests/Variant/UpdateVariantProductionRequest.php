<?php

namespace App\Http\Requests\Variant;

use App\Http\Requests\AdminFormRequest;
use App\Models\Product;
use App\Support\VariantSizes;
use Illuminate\Validation\Validator;

/**
 * A aba "Producao" do produto: o tempo de impressao e a gramagem de cada
 * TAMANHO, a escrever de uma vez em todas as variantes desse tamanho.
 *
 * Um par por tamanho e nao um por variante: entre as cores e os materiais de
 * um tamanho o tempo de maquina e o plastico gasto nao mudam — a peca e a
 * mesma. Entre tamanhos mudam: a escultura de 20cm gasta quase cinco vezes o
 * plastico da de 10cm. Um produto sem tamanhos tem um grupo so
 * (`size_label` null), e e o caso de quase todos.
 *
 * As horas e os minutos chegam separados pela mesma razao do
 * PricingPreviewRequest — "1,30" tanto se le como uma hora e trinta como 1,3
 * horas — e os tectos sao os do StoreVariantRequest, que guarda os mesmos dois
 * campos uma variante de cada vez.
 *
 * Os tres campos sao nullable: um campo vazio LIMPA o valor nas variantes do
 * tamanho, e e a unica maneira de desfazer um engano sem abrir ficha a ficha.
 * Zero e outra coisa — uma peca de zero gramas nao existe, e o calculo
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
            'sizes' => ['required', 'array', 'min:1', 'max:50'],
            'sizes.*' => ['array:size_label,hours,minutes,weight_grams'],
            'sizes.*.size_label' => ['present', 'nullable', 'string', 'max:60'],
            'sizes.*.hours' => ['nullable', 'integer', 'min:0', 'max:999'],
            'sizes.*.minutes' => ['nullable', 'integer', 'min:0', 'max:59'],
            'sizes.*.weight_grams' => ['nullable', 'integer', 'min:0', 'max:99999'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'sizes.*.size_label' => 'tamanho',
            'sizes.*.hours' => 'horas',
            'sizes.*.minutes' => 'minutos',
            'sizes.*.weight_grams' => 'gramagem',
        ];
    }

    /**
     * Cada tamanho tem de ser do produto e aparecer uma vez so. Feito aqui e
     * nao com `distinct`/`in`: a comparacao e a do VariantSizes::key() — sem
     * caixa, sem espacos nas pontas — e o null (sem tamanho) tem de ser
     * verificado como os outros, coisa que uma regra a seguir a `nullable`
     * nunca faz.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('sizes')) {
                    return;
                }

                /** @var Product $product */
                $product = $this->route('product');

                $known = $product->variants()
                    ->pluck('size_label')
                    ->map(fn (?string $label): string => VariantSizes::key($label))
                    ->unique()
                    ->all();

                $seen = [];

                foreach ((array) $this->input('sizes', []) as $index => $size) {
                    $label = is_array($size) ? ($size['size_label'] ?? null) : null;

                    if ($label !== null && ! is_string($label)) {
                        continue; // O `string` das regras ja disse o que ha a dizer.
                    }

                    $key = VariantSizes::key($label);

                    if (! in_array($key, $known, true)) {
                        $validator->errors()->add("sizes.{$index}.size_label", $key === ''
                            ? 'Todas as variantes deste produto têm tamanho.'
                            : "Este produto não tem o tamanho «{$label}».");

                        continue;
                    }

                    if (in_array($key, $seen, true)) {
                        $validator->errors()->add("sizes.{$index}.size_label", 'Este tamanho aparece duas vezes.');
                    }

                    $seen[] = $key;
                }
            },
        ];
    }

    /**
     * Ja traduzido para o que a base de dados guarda: minutos totais e gramas,
     * ou null quando o campo veio vazio, tamanho a tamanho.
     *
     * @return list<array{size_label: string|null, values: array{printing_time_minutes: int|null, filament_weight_grams: int|null}}>
     */
    public function sizes(): array
    {
        /** @var array<int, array{size_label?: string|null, hours?: int|string|null, minutes?: int|string|null, weight_grams?: int|string|null}> $sizes */
        $sizes = (array) $this->validated('sizes');

        return array_values(array_map(fn (array $size): array => [
            'size_label' => $size['size_label'] ?? null,
            'values' => self::production($size),
        ], $sizes));
    }

    /**
     * @param  array{hours?: int|string|null, minutes?: int|string|null, weight_grams?: int|string|null}  $size
     * @return array{printing_time_minutes: int|null, filament_weight_grams: int|null}
     */
    private static function production(array $size): array
    {
        $hours = $size['hours'] ?? null;
        $minutes = $size['minutes'] ?? null;

        // Horas E minutos vazios = sem tempo. Um dos dois preenchido chega:
        // "0 h 45 min" e "2 h" sao ambos tempos.
        $totalMinutes = $hours === null && $minutes === null
            ? null
            : (int) $hours * 60 + (int) $minutes;

        $grams = $size['weight_grams'] ?? null;

        return [
            'printing_time_minutes' => $totalMinutes,
            'filament_weight_grams' => $grams === null ? null : (int) $grams,
        ];
    }
}
