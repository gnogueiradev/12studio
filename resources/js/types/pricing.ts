import type { Option } from '@/lib/options';

/**
 * A calculadora de custos, revenda e preço final.
 *
 * O preço deixou de sair da gramagem: duas peças de 32 g podem demorar 30
 * minutos ou 4 horas, o material custa o mesmo e a produção não. E deixou de
 * sair de um custo/hora agregado: a energia, a amortização da máquina e a
 * manutenção são parcelas separadas, porque são decisões separadas.
 *
 * Tudo o que está aqui é calculado no servidor — nunca no browser. Ver
 * `app/Services/PricingCalculator.php`.
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
    /** Saco, caixa, etiqueta: mais ou menos igual para tudo o que sai da loja. */
    packaging_cost: string;
    /** Ímanes, argolas, feltro, parafusos: específicos desta peça. */
    components_cost: string;
    /**
     * Minutos em que há mesmo alguém a mexer na peça. Null é "usa a definição
     * global" — diferente de zero, que é "esta peça não leva trabalho nenhum".
     */
    active_labor_minutes: number | null;
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
 * (0,06177 € de eletricidade não cabe num cêntimo) e alimentam o painel do
 * cálculo detalhado; os cêntimos são o que se mostra em grande e o que o botão
 * "Aplicar preços" escreve nos campos da variante.
 */
export type PricingBreakdown = {
    mode: PricingMode;
    quantity: number;
    /** Minutos de trabalho humano do trabalho todo (em lote: montagem + peças). */
    laborMinutes: number;

    // As sete parcelas do custo, já por unidade.
    filamentCostMicros: number;
    electricityCostMicros: number;
    depreciationCostMicros: number;
    maintenanceCostMicros: number;
    laborCostMicros: number;
    packagingCostMicros: number;
    componentsCostMicros: number;

    /** A soma das sete, antes do risco de falhas. */
    baseProductionCostMicros: number;
    /** O que o risco acrescentou: custo real menos subtotal. */
    failureCostMicros: number;
    productionCostMicros: number;

    rawWholesalePriceMicros: number;
    wholesalePriceMicros: number;
    rawRetailPriceMicros: number;
    retailPriceMicros: number;
    /** Comissões do canal sobre o preço ao cliente. Zero = o bloco não aparece. */
    channelFeeMicros: number;

    /** Pontos base: 500 = 5 %, 4000 = 40 %. */
    failureRateBp: number;
    targetWholesaleMarginBp: number;
    targetResellerMarginBp: number;

    productionCostCents: number;
    wholesalePriceCents: number;
    retailPriceCents: number;
    channelFeeCents: number;
    wholesaleProfitCents: number;
    directProfitCents: number;
    netDirectProfitCents: number;
    resellerProfitCents: number;

    /** Pontos base: 4741 = 47,41 %. Todas sobre a VENDA, menos o markup. */
    wholesaleMarginBp: number;
    directMarginBp: number;
    netDirectMarginBp: number;
    resellerMarginBp: number;
    resellerMarkupBp: number;

    /** Totais do trabalho = unidade × quantidade, nos dois modos. */
    job: {
        productionCostCents: number;
        wholesalePriceCents: number;
        retailPriceCents: number;
        wholesaleProfitCents: number;
        directProfitCents: number;
        netDirectProfitCents: number;
    };
};

/**
 * O que a página precisa de saber sobre cada impressora para a escolher.
 *
 * O €/h vem já derivado do servidor (energia + amortização + manutenção): a
 * tarifa é global e não tem de viajar até ao browser para lá ser multiplicada.
 */
export type PrinterProfileOption = {
    id: number;
    name: string;
    hourlyCostMicros: number;
    isDefault: boolean;
};

export type PrinterProfileRow = {
    id: number;
    name: string;
    averagePowerWatts: number;
    purchasePriceCents: number;
    lifetimeHours: number;
    maintenanceMicrosPerHour: number;
    /** O agregado que as quatro colunas produzem. */
    hourlyCostMicros: number;
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
    defaultHourlyCostMicros: number | null;
    averageHourlyCostMicros: number;
};

/**
 * O que o modal manda ao servidor, a criar e a editar.
 *
 * Sem `is_default` nem `active` de propósito: predefinir e arquivar são um
 * clique na própria linha da listagem, e uma chave que não vai no pedido é uma
 * coluna que o servidor não toca.
 *
 * Quatro campos em vez de um custo/hora: o €/h passou a ser derivado. A
 * potência e a vida útil são inteiros (Wh/h ≡ W, e ninguém estima a vida útil
 * ao minuto); os outros dois viram cêntimos e micros no PrinterProfileService.
 */
export type PrinterProfileFormData = {
    name: string;
    average_power_watts: number;
    purchase_price: string;
    lifetime_hours: number;
    maintenance_rate: string;
    notes: string;
    sort_order: number;
};

/** Estados de uma impressora, para as chips e a pastilha da listagem. */
export const PRINTER_STATES: (Option & { chipLabel: string })[] = [
    { value: 'active', label: 'Ativa', chipLabel: 'Ativas' },
    { value: 'archived', label: 'Arquivada', chipLabel: 'Arquivadas' },
];

/**
 * Os parâmetros da calculadora, em unidades humanas. Tudo escalar: aqui já
 * viveram duas tabelas de faixas (multiplicador por custo, manuseamento por
 * peso) e saíram com a fórmula que as usava.
 *
 * Os números da máquina não estão aqui — vivem em /admin/impressoras, porque
 * duas máquinas não custam o mesmo por hora. O que é global é a tarifa da luz.
 */
export type PricingSettingsForm = {
    electricity_price: string;
    labor_rate: string;
    active_labor_minutes: number;
    setup_labor_minutes: number;
    failure_rate_percent: string;
    wholesale_margin_percent: string;
    reseller_margin_percent: string;
    minimum_wholesale_price: string;
    channel_fixed_fee: string;
    channel_percentage_fee: string;
};
