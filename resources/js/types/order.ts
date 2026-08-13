/*
 * "Pagamento confirmado" e nao "Pago" de proposito: nas listagens o estado da
 * encomenda aparece ao lado do estado de pagamento, onde "Pago" ja e a
 * etiqueta de PAYMENT_STATUSES. Dois "Pago" na mesma linha, a dizerem coisas
 * diferentes, era o que havia antes.
 */
export const ORDER_STATUSES = [
    { value: 'pending_payment', label: 'A aguardar pagamento' },
    { value: 'paid', label: 'Pagamento confirmado' },
    { value: 'in_production', label: 'Em produção' },
    { value: 'ready_to_ship', label: 'Pronto a enviar' },
    { value: 'shipped', label: 'Enviado' },
    { value: 'delivered', label: 'Entregue' },
    { value: 'cancelled', label: 'Cancelado' },
    { value: 'refunded', label: 'Reembolsado' },
] as const;

export const PAYMENT_STATUSES = [
    { value: 'pending', label: 'Pendente' },
    { value: 'paid', label: 'Pago' },
    { value: 'partially_refunded', label: 'Parcialmente reembolsado' },
    { value: 'refunded', label: 'Reembolsado' },
    { value: 'failed', label: 'Falhou' },
] as const;

export const PAYMENT_METHODS = [
    { value: 'card', label: 'Cartão' },
    { value: 'multibanco', label: 'Multibanco' },
    { value: 'mbway', label: 'MB WAY' },
    { value: 'cash', label: 'Dinheiro' },
    { value: 'bank_transfer', label: 'Transferência' },
    { value: 'vinted', label: 'Vinted' },
    { value: 'other', label: 'Outro' },
] as const;

export const SALES_CHANNELS = [
    { value: 'website', label: 'Loja online' },
    { value: 'vinted', label: 'Vinted' },
    { value: 'instagram', label: 'Instagram' },
    { value: 'manual', label: 'Manual' },
] as const;

export const PRODUCTION_STATUSES = [
    { value: 'not_required', label: 'Sem produção' },
    { value: 'awaiting_production', label: 'Por produzir' },
    { value: 'printing', label: 'A imprimir' },
    { value: 'quality_check', label: 'Controlo de qualidade' },
    { value: 'ready', label: 'Pronto' },
] as const;

/**
 * Estados que têm sempre chip na listagem de encomendas, pela ordem do
 * pipeline. Os restantes (entregue, cancelado, reembolsado) são finais e
 * raros: só ganham chip quando têm encomendas ou quando estão selecionados.
 */
export const ORDER_STATUS_CHIPS = [
    'pending_payment',
    'paid',
    'in_production',
    'ready_to_ship',
    'shipped',
] as const;

/** Colunas do quadro de produção, pela ordem do pipeline. */
export const PRODUCTION_BOARD_COLUMNS = [
    'awaiting_production',
    'printing',
    'quality_check',
    'ready',
] as const;

/**
 * Vizinhos no pipeline. Vivem aqui — e não em cada página — porque a ordem
 * das colunas já é `PRODUCTION_BOARD_COLUMNS`: escrevê-la outra vez à mão é
 * um sítio a mais para ficar dessincronizada do `OrderService`.
 *
 * O `previous` existe porque a produção anda nos dois sentidos (uma peça que
 * chumba no controlo de qualidade volta à impressora). Quem recusa o recuo é
 * só o servidor, quando a encomenda já saiu.
 */
function step(current: string, by: 1 | -1): string | null {
    const index = PRODUCTION_BOARD_COLUMNS.indexOf(
        current as (typeof PRODUCTION_BOARD_COLUMNS)[number],
    );

    return index === -1 || PRODUCTION_BOARD_COLUMNS[index + by] === undefined
        ? null
        : PRODUCTION_BOARD_COLUMNS[index + by];
}

/** Próximo estado de produção de um item, ou null se já está pronto. */
export function nextProductionStatus(current: string): string | null {
    return step(current, 1);
}

/** Estado anterior, ou null se já está na primeira coluna. */
export function previousProductionStatus(current: string): string | null {
    return step(current, -1);
}

export type OrderRow = {
    id: number;
    orderNumber: string;
    customerName: string;
    email: string;
    status: string;
    paymentStatus: string;
    salesChannel: string;
    totalCents: number;
    stockIssue: boolean;
    itemsCount: number;
    /** Nome do primeiro artigo; null quando a encomenda não tem linhas. */
    itemsSummary: string | null;
    /** Soma das quantidades — o "2 un." do resumo. */
    itemsQty: number;
    tags: string[];
    createdAt: string | null;
    /** "09 ago", já formatado em PT pelo servidor. */
    createdAtShort: string | null;
};

export type OrderItemRow = {
    id: number;
    productName: string;
    variantLabel: string | null;
    sku: string | null;
    qty: number;
    unitPriceCents: number;
    catalogUnitPriceCents: number | null;
    priceOverrideReason: string | null;
    personalizationSurchargeCents: number;
    lineTotalCents: number;
    vatRate: number;
    fulfillmentMode: string;
    productionStatus: string;
    /** Já renderizada com labels — nunca JSON bruto. */
    personalization: { label: string; value: string }[];
};

export type OrderAddress = {
    name?: string;
    line1?: string;
    line2?: string | null;
    postalCode?: string;
    city?: string;
    country?: string;
    phone?: string | null;
};

export type TimelineEntry = {
    id: string;
    kind: 'order' | 'item';
    /** Nome do item, quando `kind` é 'item'. */
    subject: string | null;
    fromStatus: string | null;
    toStatus: string;
    note: string | null;
    author: string | null;
    at: string;
};

export type OrderDetail = {
    id: number;
    orderNumber: string;
    customerName: string;
    email: string;
    phone: string | null;
    nif: string | null;
    customerId: number | null;
    status: string;
    paymentStatus: string;
    paymentMethod: string | null;
    salesChannel: string;
    externalOrderReference: string | null;
    createdBy: string | null;
    subtotalCents: number;
    shippingCents: number;
    totalCents: number;
    shippingAddress: OrderAddress | null;
    billingAddress: OrderAddress | null;
    shippingMethodName: string | null;
    trackingNumber: string | null;
    trackingUrl: string | null;
    adminNote: string | null;
    /** Nomes, não ids — o campo de etiquetas nunca lida com chaves. */
    tags: string[];
    stockIssue: boolean;
    createdAt: string | null;
    paidAt: string | null;
    shippedAt: string | null;
    deliveredAt: string | null;
    cancelledAt: string | null;
    /** Estados de fulfilment ainda alcançáveis a partir do atual. */
    availableStatuses: string[];
    items: OrderItemRow[];
    timeline: TimelineEntry[];
};

export type ManualOrderItem = {
    /** null = linha livre (fora do catálogo). */
    variant_id: number | null;
    product_name: string;
    variant_label: string;
    sku: string;
    unit_price: string;
    qty: number;
    price_override_reason: string;
    fulfillment_mode: string;
    vat_rate: number;
};

export type ManualOrderFormData = {
    user_id: number | null;
    customer_name: string;
    email: string;
    phone: string;
    nif: string;
    sales_channel: string;
    external_order_reference: string;
    payment_method: string;
    payment_status: string;
    shipping_price: string;
    shipping_method_name: string;
    line1: string;
    line2: string;
    postal_code: string;
    city: string;
    country: string;
    admin_note: string;
    send_confirmation: boolean;
    items: ManualOrderItem[];
    /** Rascunho de onde o formulário foi retomado; o servidor apaga-o ao criar. */
    draft_id: number | null;
};

/** Encomenda manual guardada a meio — é o formulário inteiro, tal e qual. */
export type OrderDraft = {
    id: number;
    payload: ManualOrderFormData;
};

export type OrderDraftRow = {
    id: number;
    /** null enquanto o nome do cliente estiver por escrever. */
    customerName: string | null;
    itemsCount: number;
    totalCents: number;
    updatedAt: string | null;
};

/** Cartão do quadro de produção. */
export type ProductionCard = {
    id: number;
    orderId: number;
    orderNumber: string;
    customerName: string;
    productName: string;
    variantLabel: string | null;
    qty: number;
    productionStatus: string;
    personalization: { label: string; value: string }[];
    orderedAt: string | null;
    /** Estimativa da variante × quantidade; null quando não está preenchida. */
    estimatedMinutes: number | null;
    /** Hora `HH:MM` da última entrada em "A imprimir". */
    startedPrintingAt: string | null;
    /** Posição entre os artigos em produção da mesma encomenda, 1-based. */
    positionInOrder: number;
    totalInOrder: number;
    /** Todos os artigos em produção da encomenda já estão prontos. */
    orderReadyToShip: boolean;
};
