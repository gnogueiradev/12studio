import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { AdminTable } from '@/components/admin/admin-table';
import type { Column } from '@/components/admin/admin-table';
import { PageHeader } from '@/components/admin/page-header';
import { Pagination } from '@/components/admin/pagination';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatCents } from '@/lib/money';
import { create, edit, index } from '@/routes/admin/clientes';
import type { CustomerRow } from '@/types/customer';
import type { Paginated } from '@/types/pagination';

type Props = {
    customers: Paginated<CustomerRow>;
    filters: { search: string };
};

export default function CustomersIndex({ customers, filters }: Props) {
    const [search, setSearch] = useState(filters.search);

    const applySearch = (event: React.FormEvent) => {
        event.preventDefault();
        router.get(
            index().url,
            { search },
            { preserveState: true, replace: true },
        );
    };

    const columns: Column<CustomerRow>[] = [
        {
            key: 'name',
            header: 'Nome',
            cell: (customer) => (
                <Link
                    href={edit(customer.id)}
                    className="font-medium hover:underline"
                >
                    {customer.name}
                </Link>
            ),
        },
        {
            key: 'email',
            header: 'Email',
            className: 'text-muted-foreground',
            cell: (customer) => customer.email,
        },
        {
            key: 'city',
            header: 'Localidade',
            className: 'text-muted-foreground',
            cell: (customer) => customer.city ?? '—',
        },
        {
            key: 'orders',
            header: 'Encomendas',
            cell: (customer) => customer.ordersCount,
        },
        {
            key: 'total',
            header: 'Total pago',
            cell: (customer) => formatCents(customer.paidTotalCents),
        },
        {
            key: 'actions',
            header: '',
            className: 'text-right',
            cell: (customer) => (
                <Button variant="outline" size="sm" asChild>
                    <Link href={edit(customer.id)}>Abrir</Link>
                </Button>
            ),
        },
    ];

    return (
        <>
            <Head title="Clientes" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Clientes"
                    description="Quem compra na loja e nos outros canais."
                >
                    <Button asChild>
                        <Link href={create()}>Novo cliente</Link>
                    </Button>
                </PageHeader>

                <form onSubmit={applySearch} className="flex max-w-sm gap-2">
                    <Input
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        placeholder="Nome, email ou NIF"
                    />
                    <Button type="submit" variant="outline">
                        Procurar
                    </Button>
                </form>

                <AdminTable
                    columns={columns}
                    rows={customers.data}
                    rowKey={(customer) => customer.id}
                    empty={
                        filters.search
                            ? 'Nenhum cliente corresponde à procura.'
                            : 'Ainda não há clientes — cria o primeiro.'
                    }
                />

                <Pagination page={customers} noun="clientes" />
            </div>
        </>
    );
}

CustomersIndex.layout = {
    breadcrumbs: [
        { title: 'Backoffice', href: '/admin' },
        { title: 'Clientes', href: index() },
    ],
};
