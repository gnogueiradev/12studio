import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import { useState } from 'react';
import { AdminTable } from '@/components/admin/admin-table';
import type { Column } from '@/components/admin/admin-table';
import { PageHeader } from '@/components/admin/page-header';
import { Pagination } from '@/components/admin/pagination';
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
import { create, index, show } from '@/routes/admin/encomendas';
import type { OrderRow } from '@/types/order';
import {
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
};

type Props = {
    orders: Paginated<OrderRow>;
    filters: Filters;
};

// O Radix Select não aceita value="" — sentinela para "sem filtro".
const ALL = 'all';

export default function OrdersIndex({ orders, filters }: Props) {
    const [search, setSearch] = useState(filters.search);

    const applyFilters = (changes: Partial<Filters>) => {
        const next = { ...filters, search, ...changes };

        router.get(
            index().url,
            Object.fromEntries(
                Object.entries(next).filter(([, value]) => value !== ''),
            ),
            { preserveState: true, replace: true },
        );
    };

    const columns: Column<OrderRow>[] = [
        {
            key: 'number',
            header: 'Nº',
            cell: (order) => (
                <div className="flex items-center gap-2">
                    <Link
                        href={show(order.id)}
                        className="font-medium hover:underline"
                    >
                        {order.orderNumber}
                    </Link>
                    {order.stockIssue && (
                        <AlertTriangle
                            className="size-4 text-amber-600"
                            aria-label="Problema de stock"
                        />
                    )}
                </div>
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
            key: 'payment',
            header: 'Pagamento',
            cell: (order) => (
                <StatusBadge
                    value={order.paymentStatus}
                    label={label(PAYMENT_STATUSES, order.paymentStatus)}
                />
            ),
        },
        {
            key: 'total',
            header: 'Total',
            className: 'text-right',
            cell: (order) => formatCents(order.totalCents),
        },
        {
            key: 'date',
            header: 'Data',
            className: 'text-muted-foreground',
            cell: (order) => order.createdAt ?? '—',
        },
    ];

    return (
        <>
            <Head title="Encomendas" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Encomendas"
                    description="Loja online, Vinted, Instagram e vendas presenciais no mesmo sítio."
                >
                    <Button asChild>
                        <Link href={create()}>Nova encomenda</Link>
                    </Button>
                </PageHeader>

                <div className="flex flex-wrap items-center gap-2">
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            applyFilters({});
                        }}
                        className="flex gap-2"
                    >
                        <Input
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Nº, nome ou email"
                            className="w-56"
                        />
                        <Button type="submit" variant="outline">
                            Procurar
                        </Button>
                    </form>

                    <Select
                        value={filters.status || ALL}
                        onValueChange={(value) =>
                            applyFilters({ status: value === ALL ? '' : value })
                        }
                    >
                        <SelectTrigger className="w-48">
                            <SelectValue placeholder="Estado" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>
                                Todos os estados
                            </SelectItem>
                            {ORDER_STATUSES.map((status) => (
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
                </div>

                <AdminTable
                    columns={columns}
                    rows={orders.data}
                    rowKey={(order) => order.id}
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
