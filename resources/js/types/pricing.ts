import type { Option } from '@/lib/options';

/**
 * A calculadora de custos, revenda e preço final.
 *
 * O preço deixou de sair da gramagem: duas peças de 32 g podem demorar 30
 * minutos ou 4 horas, o material custa o mesmo e a produção não. O tempo de
 * impressão é input do cálculo, e tudo o que está aqui é calculado no servidor
 * — nunca no browser. Ver `app/Services/PricingCalculator.php`.
 */

export const PRICING_MODES = {
    perUnit: 'per_unit',
    batch: 'batch',
} as const;

export type PricingMode = (typeof PRICING_MODES)[keyof typeof PRICING_MODES];

/** Os campos do formulário, tal como viajam no URL. */
export type PricingFormFields = {
    mode: PricingMode;
    weight_grams: number;
    /**
     * Horas e minutos separados, e não um decimal: "1,30" tanto se lê como uma
     * hora e trinta como 1,3 horas, e a diferença são 12 minutos de máquina
     * em cada peça.
     */
    hours: number;
    minutes: number;
    extra_cost: string;
    quantity: number;
    /**
     * O filamento, e a única fonte do €/kg — a bobine é quem tem preço. Só pode
     * ser um dos materiais criados na loja: não há preço escrito à mão.
     *
     * Null é "ainda não escolhido", e nesse estado não há preço nenhum.
     */
    material_id: number | null;
    printer_profile_id: number | null;
};

/**
 * O resultado de um cálculo.
 *
 * Vem em micro-euros E em cêntimos de propósito. Os micros são a precisão real
 * (0,10352 € de reserva de falha não cabe num cêntimo) e alimentam o painel do
 * cálculo detalhado; os cêntimos são o que se mostra em grande e o que o botão
 * "Aplicar preços" escreve nos campos da variante.
 */
export type PricingBreakdown = {
    mode: PricingMode;
    quantity: number;

    filamentCostMicros: number;
    machineCostMicros: number;
    handlingCostMicros: number;
    failureReserveMicros: number;
    extraCostMicros: number;
    productionCostMicros: number;

    /** Pontos base: 20000 = 2,00×. */
    resaleMultiplierBp: number;
    rawResalePriceMicros: number;
    rawRetailPriceMicros: number;
    minimumRetailPriceMicros: number;
    /** True quando a proteção da margem do revendedor subiu o preço. */
    retailBumped: boolean;

    productionCostCents: number;
    resalePriceCents: number;
    retailPriceCents: number;
    producerProfitCents: number;
    resellerProfitCents: number;

    /** Pontos base: 5293 = 52,93 %. */
    producerMarginBp: number;
    resellerMarginBp: number;
    resellerMarkupBp: number;

    /** Totais do trabalho = unidade × quantidade, nos dois modos. */
    job: {
        productionCostCents: number;
        resalePriceCents: number;
        retailPriceCents: number;
    };
};

/** O que a página precisa de saber sobre cada impressora para a escolher. */
export type PrinterProfileOption = {
    id: number;
    name: string;
    hourlyRateCents: number;
    isDefault: boolean;
};

export type PrinterProfileRow = {
    id: number;
    name: string;
    hourlyRateCents: number;
    notes: string | null;
    isDefault: boolean;
    active: boolean;
    sortOrder: number;
    state: string;
    /** Quantas variantes ficam presas a esta máquina. */
    variantsCount: number;
};

export type PrinterProfileStats = {
    activeCount: number;
    defaultName: string | null;
    defaultRateCents: number | null;
    averageRateCents: number;
};

/**
 * O que o modal manda ao servidor, a criar e a editar.
 *
 * Sem `is_default` nem `active` de propósito: predefinir e arquivar são um
 * clique na própria linha da listagem, e uma chave que não vai no pedido é uma
 * coluna que o servidor não toca. Os euros do `hourly_rate` viram cêntimos no
 * PrinterProfileService.
 */
export type PrinterProfileFormData = {
    name: string;
    hourly_rate: string;
    notes: string;
    sort_order: number;
};

/** Estados de uma impressora, para as chips e a pastilha da listagem. */
export const PRINTER_STATES: (Option & { chipLabel: string })[] = [
    { value: 'active', label: 'Ativa', chipLabel: 'Ativas' },
    { value: 'archived', label: 'Arquivada', chipLabel: 'Arquivadas' },
];

/** Uma linha de faixa no formulário de definições de preços. */
export type PricingTierField = {
    /** Vazio/null = faixa aberta, a última. */
    up_to: string | null;
    value: string;
};

export type PricingHandlingTierField = {
    up_to_grams: number | null;
    value: string;
};

export type PricingSettingsForm = {
    failure_reserve_percent: string;
    minimum_resale_price: string;
    retail_multiplier: string;
    minimum_retail_multiplier: string;
    batch_job_handling: string;
    batch_unit_handling: string;
    resale_multipliers: PricingTierField[];
    handling_tiers: PricingHandlingTierField[];
};
