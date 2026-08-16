<?php

namespace App\Http\Requests\Pricing;

use App\Models\Material;
use App\Support\Money;
use App\Support\PricingInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Os campos de um calculo de preco, partilhados pela pagina /admin/calculadora
 * e pela pre-visualizacao dentro do formulario de variante.
 *
 * O tempo entra em HORAS e MINUTOS separados, e nao num decimal. E deliberado:
 * "1,30" tanto se le como uma hora e trinta como como 1,3 horas, e a diferenca
 * sao 12 minutos de maquina em cada peca. Com dois campos nao ha leitura
 * ambigua nenhuma, e o que se guarda e sempre o total em minutos.
 */
class PricingPreviewRequest extends FormRequest
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
     * O admin tanto cola "17,00" como escreve "17.00".
     */
    protected function prepareForValidation(): void
    {
        foreach (['packaging_cost', 'components_cost'] as $field) {
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
            'mode' => ['nullable', Rule::in(PricingInput::MODES)],
            // Tecto de 50 kg: acima disso e um engano de unidade (kg escritos
            // como gramas), nao uma peca.
            'weight_grams' => ['nullable', 'integer', 'min:0', 'max:50000'],
            'hours' => ['nullable', 'integer', 'min:0', 'max:999'],
            'minutes' => ['nullable', 'integer', 'min:0', 'max:59'],
            'packaging_cost' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
            'components_cost' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
            // Nullable a serio: vazio quer dizer "usa a definicao global", e e
            // diferente de zero, que quer dizer "esta peca nao leva trabalho".
            'active_labor_minutes' => ['nullable', 'integer', 'min:0', 'max:600'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'material_id' => ['nullable', 'integer', Rule::exists('materials', 'id')],
            'printer_profile_id' => ['nullable', 'integer', Rule::exists('printer_profiles', 'id')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'mode' => 'modo',
            'weight_grams' => 'peso',
            'hours' => 'horas',
            'minutes' => 'minutos',
            'packaging_cost' => 'embalagem',
            'components_cost' => 'componentes',
            'active_labor_minutes' => 'trabalho ativo',
            'quantity' => 'quantidade',
            'material_id' => 'material',
            'printer_profile_id' => 'impressora',
        ];
    }

    public function mode(): string
    {
        $mode = $this->input('mode');

        return in_array($mode, PricingInput::MODES, true)
            ? (string) $mode
            : PricingInput::MODE_PER_UNIT;
    }

    public function weightGrams(): int
    {
        return (int) $this->input('weight_grams', 0);
    }

    /** Horas e minutos -> minutos, que e como se guarda e como se calcula. */
    public function printTimeMinutes(): int
    {
        return (int) $this->input('hours', 0) * 60 + (int) $this->input('minutes', 0);
    }

    public function quantity(): int
    {
        return max(1, (int) $this->input('quantity', 1));
    }

    public function packagingCostCents(): int
    {
        return $this->costCents('packaging_cost');
    }

    public function componentsCostCents(): int
    {
        return $this->costCents('components_cost');
    }

    /**
     * Minutos de trabalho ativo, ou null para a definicao global.
     *
     * Um campo vazio nao pode virar zero: zero e uma afirmacao ("esta peca nao
     * leva trabalho nenhum") e vazio e a ausencia dela.
     */
    public function activeLaborMinutes(): ?int
    {
        $value = $this->input('active_labor_minutes');

        return is_numeric($value) ? (int) $value : null;
    }

    private function costCents(string $field): int
    {
        $value = $this->input($field);

        return is_string($value) || is_numeric($value) ? Money::fromDecimal((string) $value) : 0;
    }

    public function materialId(): ?int
    {
        $value = $this->input('material_id');

        return is_numeric($value) ? (int) $value : null;
    }

    public function printerProfileId(): ?int
    {
        $value = $this->input('printer_profile_id');

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Preco por kg do filamento.
     *
     * Ha uma fonte so, e e o MATERIAL: e a bobine que tem preco — a cor e so um
     * tom. O preco escrito a mao deixou de existir de proposito. Enquanto
     * existiu, um custo podia sair de um numero que nao correspondia a filamento
     * nenhum em stock, e a calculadora abria a dizer 0,00 EUR/kg, ou seja, a
     * fingir que o plastico e de graca.
     *
     * Sem material devolve zero, e nao um valor de recurso: quem chama decide o
     * que fazer com isso. A calculadora recusa mostrar preco (ver
     * PricingCalculatorController); a ficha de variante deixa-o passar, porque
     * uma variante sem material ainda assim tem tempo de maquina a contar.
     */
    public function pricePerKgCents(): int
    {
        $materialId = $this->materialId();

        if ($materialId === null) {
            return 0;
        }

        $material = Material::query()->find($materialId);

        return $material === null ? 0 : $material->price_per_kg_cents;
    }

    /**
     * Ha dados suficientes para calcular? Sem tempo nao ha calculo nenhum —
     * essa e a regra fundamental desta versao. Sem peso tambem nao: uma peca de
     * zero gramas nao existe.
     */
    public function isCalculable(): bool
    {
        return $this->printTimeMinutes() > 0 && $this->weightGrams() > 0;
    }

    /**
     * Eco dos campos para a pagina os repor nos inputs.
     *
     * @return array{
     *     mode: string,
     *     weight_grams: int,
     *     hours: int,
     *     minutes: int,
     *     packaging_cost: string,
     *     components_cost: string,
     *     active_labor_minutes: int|null,
     *     quantity: int,
     *     material_id: int|null,
     *     printer_profile_id: int|null,
     * }
     */
    public function toFormFields(): array
    {
        return [
            'mode' => $this->mode(),
            'weight_grams' => $this->weightGrams(),
            'hours' => intdiv($this->printTimeMinutes(), 60),
            'minutes' => $this->printTimeMinutes() % 60,
            'packaging_cost' => Money::toDecimal($this->packagingCostCents()),
            'components_cost' => Money::toDecimal($this->componentsCostCents()),
            'active_labor_minutes' => $this->activeLaborMinutes(),
            'quantity' => $this->quantity(),
            'material_id' => $this->materialId(),
            'printer_profile_id' => $this->printerProfileId(),
        ];
    }
}
