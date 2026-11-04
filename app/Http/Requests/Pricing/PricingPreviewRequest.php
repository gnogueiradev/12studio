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
        foreach (['packaging_cost', 'components_cost', 'reference_filament_cost', 'price'] as $field) {
            $value = $this->input($field);

            if (is_string($value) && $value !== '') {
                $this->merge([$field => str_replace(',', '.', $value)]);
            }
        }

        // O URL traz "true"/"false" (o Inertia serializa assim os booleanos) e
        // a regra `boolean` do Laravel so aceita 0/1. Normaliza-se antes.
        if ($this->has('quote')) {
            $this->merge(['quote' => $this->boolean('quote')]);
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
            // Desligado por omissao: uma peca de catalogo nao e um orcamento.
            // So com ele entram o lucro minimo por trabalho, o minimo de mao de
            // obra, as placas, as trocas de cor e o design.
            'quote' => ['nullable', 'boolean'],
            // Um preco escolhido a avaliar, com IVA. Nao muda o calculo. Zero
            // e "nao avaliar" (e o que fica a meio de escrever no campo).
            'price' => ['nullable', 'numeric', 'min:0', 'max:99999.99'],
            // So contam em orcamentos (quote = true); a ficha de variante nao
            // os manda.
            'plates' => ['nullable', 'integer', 'min:1', 'max:100'],
            'manual_color_changes' => ['nullable', 'integer', 'min:0', 'max:100'],
            'design_minutes' => ['nullable', 'integer', 'min:0', 'max:6000'],
            'reference_filament_cost' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'mode' => 'modo',
            'quote' => 'orçamento',
            'price' => 'preço a avaliar',
            'weight_grams' => 'peso',
            'hours' => 'horas',
            'minutes' => 'minutos',
            'packaging_cost' => 'embalagem',
            'components_cost' => 'componentes',
            'active_labor_minutes' => 'trabalho ativo',
            'quantity' => 'quantidade',
            'material_id' => 'material',
            'printer_profile_id' => 'impressora',
            'plates' => 'placas',
            'manual_color_changes' => 'trocas de cor',
            'design_minutes' => 'tempo de design',
            'reference_filament_cost' => 'filamento do slicer',
        ];
    }

    /** Trabalho por encomenda? Sem o campo, nao. */
    public function isQuote(): bool
    {
        return $this->boolean('quote');
    }

    /** O preco a avaliar, com IVA, em centimos. Null quando nao veio ou e zero. */
    public function priceCents(): ?int
    {
        $value = $this->input('price');

        $cents = (is_string($value) && $value !== '') || is_numeric($value)
            ? Money::fromDecimal((string) $value)
            : 0;

        return $cents > 0 ? $cents : null;
    }

    public function plates(): int
    {
        return max(1, (int) $this->input('plates', 1));
    }

    public function manualColorChanges(): int
    {
        return max(0, (int) $this->input('manual_color_changes', 0));
    }

    public function designMinutes(): int
    {
        return max(0, (int) $this->input('design_minutes', 0));
    }

    /** Null quando nao veio: vazio nao e "o slicer disse zero". */
    public function referenceFilamentCostCents(): ?int
    {
        $value = $this->input('reference_filament_cost');

        return (is_string($value) && $value !== '') || is_numeric($value)
            ? Money::fromDecimal((string) $value)
            : null;
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
     *     quote: bool,
     *     price: string,
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
     *     plates: int,
     *     manual_color_changes: int,
     *     design_minutes: int,
     *     reference_filament_cost: string,
     * }
     */
    public function toFormFields(): array
    {
        $reference = $this->referenceFilamentCostCents();
        $price = $this->priceCents();

        return [
            'quote' => $this->isQuote(),
            'price' => $price === null ? '' : Money::toDecimal($price),
            'plates' => $this->plates(),
            'manual_color_changes' => $this->manualColorChanges(),
            'design_minutes' => $this->designMinutes(),
            'reference_filament_cost' => $reference === null ? '' : Money::toDecimal($reference),
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
