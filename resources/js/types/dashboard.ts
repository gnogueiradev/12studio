/** Formas das props do painel do backoffice (Admin\DashboardController). */

export type DashboardKpis = {
    revenue30Cents: number;
    /** null quando não há período anterior com que comparar. */
    revenueDeltaPercent: number | null;
    ordersThisWeek: number;
    awaitingPayment: number;
    paidOrders30: number;
    avgOrderCents: number;
    lowStockCount: number;
    /** Os dois primeiros produtos em falta, para a linha de contexto. */
    lowStockNames: string[];
};

export type StatusCount = {
    status: string;
    count: number;
};

export type WeekBucket = {
    /** "4 ago" — o início da semana. */
    weekLabel: string;
    /** Mês abreviado, para o eixo horizontal. */
    month: string;
    cents: number;
    /** Semana do mês corrente: sai destacada a dourado. */
    current: boolean;
};

export type RecentOrder = {
    id: number;
    orderNumber: string;
    customerName: string;
    status: string;
    totalCents: number;
    /** "Vaso ondulado · 2 un. +1" — já montado no servidor. */
    summary: string;
};

export type PrintingItem = {
    id: number;
    orderId: number;
    orderNumber: string;
    customerName: string;
    productName: string;
    variantLabel: string | null;
    qty: number;
};

export type ProductionSnapshot = {
    printing: PrintingItem[];
    /** Itens à espera de entrar na impressora. */
    queued: number;
};

export type DashboardAlert = {
    label: string;
    href: string;
};
