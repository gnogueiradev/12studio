import { Head, Link } from '@inertiajs/react';
import { AdminTable } from '@/components/admin/admin-table';
import type { Column } from '@/components/admin/admin-table';
import { PageHeader } from '@/components/admin/page-header';
import { Panel } from '@/components/admin/panel';
import { StatCard } from '@/components/admin/stat-card';
import { StatusBadge } from '@/components/admin/status-badge';
import { StatusDistribution } from '@/components/admin/status-distribution';
import { WeeklySalesChart } from '@/components/admin/weekly-sales-chart';
import { Button } from '@/components/ui/button';
import { formatCents } from '@/lib/money';
import { label } from '@/lib/options';
import { cn } from '@/lib/utils';
import { dashboard, producao } from '@/routes/admin';
import {
    create as createOrder,
    index as ordersIndex,
    show as showOrder,
} from '@/routes/admin/encomendas';
import { index as produtos } from '@/routes/admin/produtos';
import type {
    DashboardAlert,
    DashboardKpis,
    ProductionSnapshot,
    RecentOrder,
    StatusCount,
    WeekBucket,
} from '@/types/dashboard';
import { ORDER_STATUSES } from '@/types/order';

type Props = {
    today: string;
    storeOpen: boolean;
    kpis: DashboardKpis;
    statusCounts: StatusCount[];
    weeklySales: WeekBucket[];
    recentOrders: RecentOrder[];
    production: ProductionSnapshot;
    alerts: DashboardAlert[];
};

export default function AdminDashboard({
    today,
    storeOpen,
    kpis,
    statusCounts,
    weeklySales,
    recentOrders,
    production,
    alerts,
}: Props) {
    const openOrders = statusCounts.reduce(
        (sum, entry) => sum + entry.count,
        0,
    );

    const columns: Column<RecentOrder>[] = [
        {
            key: 'number',
            header: 'Encomenda',
            cell: (order) => (
                <>
                    <Link
                        href={showOrder(order.id)}
                        className="font-medium tabular-nums hover:underline"
                    >
                        {order.orderNumber}
                    </Link>
                    <div className="text-xs text-muted-foreground">
                        {order.summary}
                    </div>
                </>
            ),
        },
        {
            key: 'customer',
            header: 'Cliente',
            className: 'text-muted-foreground',
            cell: (order) => order.customerName,
        },
        {
            key: 'status',
            header: 'Estado',
            cell: (order) => (
                <StatusBadge
                    value={order.status}
                    label={label(ORDER_STATUSES, order.status)}
                />
            ),
        },
        {
            key: 'total',
            header: 'Total',
            className: 'text-right tabular-nums',
            cell: (order) => formatCents(order.totalCents),
        },
    ];

    return (
        <>
            <Head title="Backoffice" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <PageHeader title="Backoffice 12studio" description={today}>
                    <span className="flex items-center gap-2 rounded-full border border-border px-3 py-1.5 text-xs text-muted-foreground">
                        <span
                            className={cn(
                                'size-1.5 rounded-full',
                                storeOpen ? 'bg-success' : 'bg-warning',
                            )}
                        />
                        {storeOpen ? 'Loja aberta' : 'Loja em pré-lançamento'}
                    </span>
                    <Button asChild variant="outline">
                        <Link href={createOrder()}>Nova encomenda</Link>
                    </Button>
                    <Button asChild>
                        {/*
                         * O produto novo nasce no modal da listagem — o atalho
                         * leva lá e pede-lhe para abrir, em vez de duplicar o
                         * formulário numa página só para este botão.
                         */}
                        <Link href={produtos({ query: { novo: 1 } })}>
                            Novo produto
                        </Link>
                    </Button>
                </PageHeader>

                {alerts.length > 0 && (
                    <div className="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-xl border border-border/60 bg-warning-soft px-4 py-3 text-xs text-warning-soft-foreground">
                        <span className="font-semibold">
                            {alerts.length === 1
                                ? '1 coisa a precisar de ti'
                                : `${alerts.length} coisas a precisar de ti`}
                        </span>
                        {alerts.map((alert) => (
                            <Link
                                key={alert.label}
                                href={alert.href}
                                className="underline-offset-2 hover:underline"
                            >
                                {alert.label}
                            </Link>
                        ))}
                    </div>
                )}

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <StatCard
                        label="Receita · 30 dias"
                        value={formatCents(kpis.revenue30Cents)}
                        hint={
                            kpis.revenueDeltaPercent === null ? (
                                'Sem período anterior para comparar'
                            ) : (
                                <>
                                    <span
                                        className={
                                            kpis.revenueDeltaPercent >= 0
                                                ? 'text-success'
                                                : 'text-destructive'
                                        }
                                    >
                                        {kpis.revenueDeltaPercent >= 0 && '+'}
                                        {kpis.revenueDeltaPercent}%
                                    </span>
                                    <span>vs. 30 dias antes</span>
                                </>
                            )
                        }
                    />
                    <StatCard
                        label="Encomendas · esta semana"
                        value={String(kpis.ordersThisWeek)}
                        hint={
                            kpis.awaitingPayment === 1
                                ? '1 à espera de pagamento'
                                : `${kpis.awaitingPayment} à espera de pagamento`
                        }
                    />
                    <StatCard
                        label="Valor médio"
                        value={formatCents(kpis.avgOrderCents)}
                        hint={
                            kpis.paidOrders30 === 1
                                ? '1 encomenda paga em 30 dias'
                                : `${kpis.paidOrders30} encomendas pagas em 30 dias`
                        }
                    />
                    <StatCard
                        label="Stock baixo"
                        value={String(kpis.lowStockCount)}
                        tone={kpis.lowStockCount > 0 ? 'warning' : 'default'}
                        hint={
                            kpis.lowStockNames.length > 0
                                ? kpis.lowStockNames.join(' · ')
                                : 'Tudo acima do limite'
                        }
                    />
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Panel
                        title="Vendas por semana"
                        aside={
                            <span className="text-muted-foreground">
                                últimas 12 semanas
                            </span>
                        }
                    >
                        <WeeklySalesChart weeks={weeklySales} />
                    </Panel>

                    <Panel
                        title="Encomendas por estado"
                        aside={
                            <span className="text-muted-foreground">
                                {openOrders === 1
                                    ? '1 aberta'
                                    : `${openOrders} abertas`}
                            </span>
                        }
                    >
                        <StatusDistribution counts={statusCounts} />
                    </Panel>
                </div>

                <div className="grid items-start gap-4 lg:grid-cols-2">
                    <section className="flex flex-col gap-4">
                        <div className="flex items-baseline justify-between gap-3">
                            <h2 className="text-sm font-semibold">
                                Encomendas recentes
                            </h2>
                            <Link
                                href={ordersIndex()}
                                className="text-xs font-medium hover:underline"
                            >
                                Ver todas
                            </Link>
                        </div>
                        <AdminTable
                            columns={columns}
                            rows={recentOrders}
                            rowKey={(order) => order.id}
                            empty="Ainda não há encomendas."
                        />
                    </section>

                    <Panel
                        title="Em produção agora"
                        aside={
                            <Link
                                href={producao()}
                                className="font-medium hover:underline"
                            >
                                Ver quadro
                            </Link>
                        }
                    >
                        {production.printing.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Nada na impressora neste momento.
                            </p>
                        ) : (
                            <ul className="flex flex-col gap-3">
                                {production.printing.map((item) => (
                                    <li
                                        key={item.id}
                                        className="flex items-baseline justify-between gap-3"
                                    >
                                        <div className="min-w-0">
                                            <p className="truncate text-sm">
                                                {item.productName}
                                                {item.variantLabel && (
                                                    <span className="text-muted-foreground">
                                                        {' · '}
                                                        {item.variantLabel}
                                                    </span>
                                                )}
                                                {item.qty > 1 && (
                                                    <span className="text-muted-foreground tabular-nums">
                                                        {' × '}
                                                        {item.qty}
                                                    </span>
                                                )}
                                            </p>
                                            <p className="truncate text-xs text-muted-foreground">
                                                {item.customerName}
                                            </p>
                                        </div>
                                        <Link
                                            href={showOrder(item.orderId)}
                                            className="flex-none text-xs font-medium tabular-nums hover:underline"
                                        >
                                            {item.orderNumber}
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}

                        {production.queued > 0 && (
                            <p className="mt-4 border-t border-border/60 pt-3 text-xs text-muted-foreground">
                                {production.queued === 1
                                    ? '1 item à espera de entrar na impressora'
                                    : `${production.queued} itens à espera de entrar na impressora`}
                            </p>
                        )}
                    </Panel>
                </div>
            </div>
        </>
    );
}

AdminDashboard.layout = {
    breadcrumbs: [
        {
            title: 'Backoffice',
            href: dashboard(),
        },
    ],
};
