import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import { AdminTable } from '@/components/admin/admin-table';
import type { Column } from '@/components/admin/admin-table';
import { FilterChip } from '@/components/admin/filter-chip';
import { PageHeader } from '@/components/admin/page-header';
import { Pagination } from '@/components/admin/pagination';
import {
    paymentTone,
    StatusBadge,
    StatusText,
} from '@/components/admin/status-badge';
import { TagChips } from '@/components/admin/tag-chips';
import { TagFilter } from '@/components/admin/tag-filter';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatCents } from '@/lib/money';
import { label } from '@/lib/options';
import type { Option } from '@/lib/options';
import { create, index, show } from '@/routes/admin/encomendas';
import { index as drafts } from '@/routes/admin/encomendas/rascunhos';
import type { OrderRow } from '@/types/order';
import {
    ORDER_STATUS_CHIPS,
    ORDER_STATUSES,
    PAYMENT_STATUSES,
    SALES_CHANNELS,
} from '@/types/order';
import type { Paginated } from '@/types/pagination';

type Filters = {
    search: string;
    status: string;
    payment_status: string;
    sales_channel: string;
    /** Slug da etiqueta, ou '' sem filtro. */
    tag: string;
};

type Props = {
    orders: Paginated<OrderRow>;
    filters: Filters;
    /** Contagem por estado, já sem o filtro de estado aplicado. */
    statusCounts: Record<string, number>;
    /** Encomendas manuais guardadas a meio por este admin. */
    draftsCount: number;
    /** Só as que alguma encomenda usa — as outras dariam zero resultados. */
    tagOptions: Option[];
};

// O Radix Select não aceita value="" — sentinela para "sem filtro".
const ALL = 'all';

/** Tempo de silêncio antes de a pesquisa ir ao servidor. */
const SEARCH_DEBOUNCE_MS = 350;

/** Filtros vazios saem da query string em vez de irem como `?status=`. */
function visit(filters: Filters) {
    router.get(
        index().url,
        Object.fromEntries(
            Object.entries(filters).filter(([, value]) => value !== ''),
        ),
        { preserveState: true, replace: true },
    );
}

export default function OrdersIndex({
    orders,
    filters,
    statusCounts,
    draftsCount,
    tagOptions,
}: Props) {
    const [search, setSearch] = useState(filters.search);

    const applyFilters = (changes: Partial<Filters>) =>
        visit({ ...filters, search, ...changes });

    /*
     * Pesquisa ao vivo, como no design. A guarda `search === filters.search`
     * trava o pedido na montagem e, sobretudo, depois de cada resposta — sem
     * ela a página que volta do servidor voltava a disparar a pesquisa que a
     * produziu, em ciclo.
     */
    useEffect(() => {
        if (search === filters.search) {
            return;
        }

        const timer = setTimeout(
            () => visit({ ...filters, search }),
            SEARCH_DEBOUNCE_MS,
        );

        return () => clearTimeout(timer);
    }, [search, filters]);

    /*
     * Os estados finais (entregue, cancelado, reembolsado) só entram na fila de
     * chips quando têm encomendas — ou quando são o filtro ativo, senão o
     * utilizador ficava com um filtro ligado e sem chip para o desligar.
     */
    const chips = ORDER_STATUSES.filter(
        (status) =>
            (ORDER_STATUS_CHIPS as readonly string[]).includes(status.value) ||
            (statusCounts[status.value] ?? 0) > 0 ||
            filters.status === status.value,
    );

    const totalOrders = Object.values(statusCounts).reduce(
        (sum, count) => sum + count,
        0,
    );

    const columns: Column<OrderRow>[] = [
        {
            key: 'number',
            header: 'Encomenda',
            cell: (order) => (
                <>
                    <div className="flex items-center gap-2">
                        <Link
                            href={show(order.id)}
                            className="font-medium tabular-nums hover:underline"
                        >
                            {order.orderNumber}
                        </Link>
                        {order.stockIssue && (
                            <AlertTriangle
                                className="size-4 text-warning"
                                aria-label="Problema de stock"
                            />
                        )}
                    </div>
                    {order.itemsSummary !== null && (
                        <div className="mt-0.5 text-xs text-muted-foreground">
                            {order.itemsSummary}
                            {order.itemsQty > 1 && ` · ${order.itemsQty} un.`}
                        </div>
                    )}
                    <TagChips tags={order.tags} />
                </>
            ),
        },
        {
            key: 'customer',
            header: 'Cliente',
            cell: (order) => (
                <>
                    <div>{order.customerName}</div>
                    <div className="text-xs text-muted-foreground">
                        {order.email}
                    </div>
                </>
            ),
        },
        {
            key: 'channel',
            header: 'Canal',
            className: 'text-muted-foreground',
            cell: (order) => label(SALES_CHANNELS, order.salesChannel),
        },
        {
            key: 'payment',
            header: 'Pagamento',
            cell: (order) => (
                <StatusText
                    tone={paymentTone(order.paymentStatus)}
                    label={label(PAYMENT_STATUSES, order.paymentStatus)}
                />
            ),
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
            key: 'date',
            header: 'Data',
            className: 'text-muted-foreground tabular-nums',
            cell: (order) => (
                <span title={order.createdAt ?? undefined}>
                    {order.createdAtShort ?? '—'}
                </span>
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
            <Head title="Encomendas" />
            <div className="flex h-full w-full max-w-[1400px] flex-1 flex-col gap-4 p-6 pb-10">
                <PageHeader
                    title="Encomendas"
                    description="Loja online, Vinted, Instagram e vendas presenciais no mesmo sítio."
                >
                    {draftsCount > 0 && (
                        <Button
                            asChild
                            variant="outline"
                            className="rounded-full"
                        >
                            <Link href={drafts()}>
                                Rascunhos · {draftsCount}
                            </Link>
                        </Button>
                    )}
                    <Button asChild className="rounded-full">
                        <Link href={create()}>Nova encomenda</Link>
                    </Button>
                </PageHeader>

                <div className="flex flex-wrap items-center gap-2">
                    <div className="relative min-w-60 flex-1 sm:max-w-85">
                        <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            type="search"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Nº, nome ou email"
                            aria-label="Procurar encomendas"
                            className="pl-9"
                        />
                    </div>

                    <Select
                        value={filters.payment_status || ALL}
                        onValueChange={(value) =>
                            applyFilters({
                                payment_status: value === ALL ? '' : value,
                            })
                        }
                    >
                        <SelectTrigger className="w-48">
                            <SelectValue placeholder="Pagamento" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>
                                Todos os pagamentos
                            </SelectItem>
                            {PAYMENT_STATUSES.map((status) => (
                                <SelectItem
                                    key={status.value}
                                    value={status.value}
                                >
                                    {status.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <Select
                        value={filters.sales_channel || ALL}
                        onValueChange={(value) =>
                            applyFilters({
                                sales_channel: value === ALL ? '' : value,
                            })
                        }
                    >
                        <SelectTrigger className="w-40">
                            <SelectValue placeholder="Canal" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>Todos os canais</SelectItem>
                            {SALES_CHANNELS.map((channel) => (
                                <SelectItem
                                    key={channel.value}
                                    value={channel.value}
                                >
                                    {channel.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <TagFilter
                        value={filters.tag}
                        options={tagOptions}
                        onChange={(tag) => applyFilters({ tag })}
                    />

                    <span className="ml-auto text-xs text-muted-foreground">
                        {orders.total}{' '}
                        {orders.total === 1 ? 'encomenda' : 'encomendas'}
                    </span>
                </div>

                <div className="flex flex-wrap gap-2 border-b border-border/60 pb-3.5">
                    <FilterChip
                        text="Todas"
                        count={totalOrders}
                        active={filters.status === ''}
                        onClick={() => applyFilters({ status: '' })}
                    />
                    {chips.map((status) => (
                        <FilterChip
                            key={status.value}
                            text={status.label}
                            count={statusCounts[status.value] ?? 0}
                            active={filters.status === status.value}
                            onClick={() =>
                                applyFilters({ status: status.value })
                            }
                        />
                    ))}
                </div>

                <AdminTable
                    columns={columns}
                    rows={orders.data}
                    rowKey={(order) => order.id}
                    rowHref={(order) => show(order.id).url}
                    empty="Nenhuma encomenda com estes filtros."
                />

                <Pagination page={orders} noun="encomendas" />
            </div>
        </>
    );
}

OrdersIndex.layout = {
    breadcrumbs: [
        { title: 'Backoffice', href: '/admin' },
        { title: 'Encomendas', href: index() },
    ],
};
