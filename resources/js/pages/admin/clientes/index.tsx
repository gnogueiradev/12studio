import { Head, router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import { AdminTable } from '@/components/admin/admin-table';
import type { Column } from '@/components/admin/admin-table';
import { CustomerDialog } from '@/components/admin/customer-dialog';
import { FilterChip } from '@/components/admin/filter-chip';
import { PageHeader } from '@/components/admin/page-header';
import { Pagination } from '@/components/admin/pagination';
import { StatCard } from '@/components/admin/stat-card';
import { StatusBadge } from '@/components/admin/status-badge';
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
import { edit, index } from '@/routes/admin/clientes';
import type { CustomerRow } from '@/types/customer';
import { CUSTOMER_TYPES, initials } from '@/types/customer';
import { SALES_CHANNELS } from '@/types/order';
import type { Paginated } from '@/types/pagination';

type Filters = {
    search: string;
    customer_type: string;
    sales_channel: string;
};

type Props = {
    customers: Paginated<CustomerRow>;
    filters: Filters;
    /** Contagem por tipo, já sem o filtro de tipo aplicado. */
    typeCounts: Record<string, number>;
    stats: {
        total: number;
        recurring: number;
        newThisMonth: number;
        /** Média por cliente que já pagou alguma coisa. */
        averagePaidCents: number;
    };
};

// O Radix Select não aceita value="" — sentinela para "sem filtro".
const ALL = 'all';

/** Tempo de silêncio antes de a pesquisa ir ao servidor. */
const SEARCH_DEBOUNCE_MS = 350;

/** Filtros vazios saem da query string em vez de irem como `?search=`. */
function visit(filters: Filters) {
    router.get(
        index().url,
        Object.fromEntries(
            Object.entries(filters).filter(([, value]) => value !== ''),
        ),
        { preserveState: true, replace: true },
    );
}

export default function CustomersIndex({
    customers,
    filters,
    typeCounts,
    stats,
}: Props) {
    const [search, setSearch] = useState(filters.search);
    const [creating, setCreating] = useState(false);

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

    const totalCustomers = Object.values(typeCounts).reduce(
        (sum, count) => sum + count,
        0,
    );

    const columns: Column<CustomerRow>[] = [
        {
            key: 'name',
            header: 'Cliente',
            cell: (customer) => (
                <div className="flex items-center gap-3">
                    <span
                        aria-hidden
                        className="grid size-8 flex-none place-items-center rounded-full bg-secondary text-xs font-semibold text-gold"
                    >
                        {initials(customer.name)}
                    </span>
                    <div>
                        <div className="font-medium">{customer.name}</div>
                        <div className="text-xs text-muted-foreground">
                            {customer.email ?? 'Sem email'}
                        </div>
                    </div>
                </div>
            ),
        },
        {
            key: 'type',
            header: 'Tipo',
            cell: (customer) => (
                <StatusBadge
                    value={customer.customerType}
                    label={label(CUSTOMER_TYPES, customer.customerType)}
                />
            ),
        },
        {
            key: 'nif',
            header: 'NIF',
            className: 'text-muted-foreground tabular-nums',
            cell: (customer) => customer.nif ?? '—',
        },
        {
            key: 'channel',
            header: 'Canal habitual',
            className: 'text-muted-foreground',
            cell: (customer) =>
                customer.habitualChannel === null
                    ? '—'
                    : label(SALES_CHANNELS, customer.habitualChannel),
        },
        {
            key: 'orders',
            header: 'Encomendas',
            className: 'text-right tabular-nums',
            cell: (customer) => customer.ordersCount,
        },
        {
            key: 'last',
            header: 'Última compra',
            className: 'text-muted-foreground tabular-nums',
            cell: (customer) => (
                <span title={customer.lastOrderAt ?? undefined}>
                    {customer.lastOrderAtShort ?? '—'}
                </span>
            ),
        },
        {
            key: 'total',
            header: 'Total gasto',
            className: 'text-right tabular-nums',
            cell: (customer) => formatCents(customer.paidTotalCents),
        },
    ];

    return (
        <>
            <Head title="Clientes" />
            <div className="flex h-full w-full max-w-[1400px] flex-1 flex-col gap-4 p-6 pb-10">
                <PageHeader
                    title="Clientes"
                    description="Quem compra na loja e nos outros canais."
                >
                    <Button
                        className="rounded-full"
                        onClick={() => setCreating(true)}
                    >
                        Novo cliente
                    </Button>
                </PageHeader>

                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <StatCard label="Total" value={String(stats.total)} />
                    <StatCard
                        label="Recorrentes"
                        value={String(stats.recurring)}
                        hint="Mais do que uma compra"
                    />
                    <StatCard
                        label="Novos este mês"
                        value={String(stats.newThisMonth)}
                    />
                    <StatCard
                        label="Valor médio"
                        value={formatCents(stats.averagePaidCents)}
                        hint="Por cliente que já pagou"
                    />
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <div className="relative min-w-60 flex-1 sm:max-w-85">
                        <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            type="search"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Nome, email ou NIF"
                            aria-label="Procurar clientes"
                            className="pl-9"
                        />
                    </div>

                    <Select
                        value={filters.sales_channel || ALL}
                        onValueChange={(value) =>
                            applyFilters({
                                sales_channel: value === ALL ? '' : value,
                            })
                        }
                    >
                        <SelectTrigger className="w-44">
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

                    <span className="ml-auto text-xs text-muted-foreground">
                        {customers.total}{' '}
                        {customers.total === 1 ? 'cliente' : 'clientes'}
                    </span>
                </div>

                <div className="flex flex-wrap gap-2 border-b border-border/60 pb-3.5">
                    <FilterChip
                        text="Todos"
                        count={totalCustomers}
                        active={filters.customer_type === ''}
                        onClick={() => applyFilters({ customer_type: '' })}
                    />
                    {CUSTOMER_TYPES.map((type) => (
                        <FilterChip
                            key={type.value}
                            text={
                                type.value === 'empresa'
                                    ? 'Empresas'
                                    : 'Particulares'
                            }
                            count={typeCounts[type.value] ?? 0}
                            active={filters.customer_type === type.value}
                            onClick={() =>
                                applyFilters({ customer_type: type.value })
                            }
                        />
                    ))}
                </div>

                <AdminTable
                    columns={columns}
                    rows={customers.data}
                    rowKey={(customer) => customer.id}
                    rowHref={(customer) => edit(customer.id).url}
                    empty="Nenhum cliente com estes filtros."
                />

                <Pagination page={customers} noun="clientes" />
            </div>

            <CustomerDialog open={creating} onOpenChange={setCreating} />
        </>
    );
}

CustomersIndex.layout = {
    breadcrumbs: [
        { title: 'Backoffice', href: '/admin' },
        { title: 'Clientes', href: index() },
    ],
};
