import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { AdminTable } from '@/components/admin/admin-table';
import type { Column } from '@/components/admin/admin-table';
import { ConfirmDialog } from '@/components/admin/confirm-dialog';
import CustomerForm from '@/components/admin/customer-form';
import { PageHeader } from '@/components/admin/page-header';
import { StatusBadge } from '@/components/admin/status-badge';
import { Button } from '@/components/ui/button';
import { formatCents } from '@/lib/money';
import { label } from '@/lib/options';
import { destroy, index, update } from '@/routes/admin/clientes';
import { show } from '@/routes/admin/encomendas';
import type { CustomerDetail, CustomerFormData } from '@/types/customer';
import {
    ORDER_STATUSES,
    PAYMENT_STATUSES,
    SALES_CHANNELS,
} from '@/types/order';

type CustomerOrderRow = {
    id: number;
    orderNumber: string;
    status: string;
    paymentStatus: string;
    salesChannel: string;
    totalCents: number;
    createdAt: string | null;
};

type Props = {
    customer: CustomerDetail;
    orders: CustomerOrderRow[];
    tagSuggestions: string[];
};

export default function CustomersEdit({
    customer,
    orders,
    tagSuggestions,
}: Props) {
    const [confirmingDelete, setConfirmingDelete] = useState(false);

    const { data, setData, patch, processing, errors } =
        useForm<CustomerFormData>({
            name: customer.name,
            email: customer.email ?? '',
            customer_type: customer.customerType,
            phone: customer.phone ?? '',
            nif: customer.nif ?? '',
            admin_note: customer.adminNote ?? '',
            tags: customer.tags,
            line1: customer.line1 ?? '',
            line2: customer.line2 ?? '',
            postal_code: customer.postalCode ?? '',
            city: customer.city ?? '',
            country: customer.country,
        });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        patch(update(customer.id).url);
    };

    const columns: Column<CustomerOrderRow>[] = [
        {
            key: 'number',
            header: 'Encomenda',
            cell: (order) => (
                <Link
                    href={show(order.id)}
                    className="font-medium hover:underline"
                >
                    {order.orderNumber}
                </Link>
            ),
        },
        {
            key: 'date',
            header: 'Data',
            className: 'text-muted-foreground',
            cell: (order) => order.createdAt ?? '—',
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
    ];

    return (
        <>
            <Head title={customer.name} />
            <div className="flex h-full w-full max-w-[1400px] flex-1 flex-col gap-8 p-6 pb-10">
                <div className="flex flex-col gap-4">
                    <PageHeader
                        title={customer.name}
                        description={`Cliente desde ${customer.createdAt ?? '—'}`}
                    >
                        {customer.canDelete && (
                            <Button
                                variant="ghost"
                                onClick={() => setConfirmingDelete(true)}
                            >
                                Apagar
                            </Button>
                        )}
                    </PageHeader>

                    <CustomerForm
                        data={data}
                        setData={setData}
                        errors={errors}
                        processing={processing}
                        onSubmit={submit}
                        submitLabel="Guardar alterações"
                        tagSuggestions={tagSuggestions}
                    />
                </div>

                <div className="flex flex-col gap-4 border-t border-border/60 pt-8">
                    <h2 className="text-lg font-semibold">Encomendas</h2>
                    <AdminTable
                        columns={columns}
                        rows={orders}
                        rowKey={(order) => order.id}
                        empty="Este cliente ainda não tem encomendas."
                    />
                </div>
            </div>

            <ConfirmDialog
                open={confirmingDelete}
                onOpenChange={setConfirmingDelete}
                title="Apagar cliente"
                description={
                    <>
                        <strong>{customer.name}</strong> é apagado
                        definitivamente, com a morada. Só é possível porque não
                        tem encomendas associadas.
                    </>
                }
                confirmLabel="Apagar"
                destructive
                onConfirm={() => {
                    router.delete(destroy(customer.id).url, {
                        onFinish: () => setConfirmingDelete(false),
                    });
                }}
            />
        </>
    );
}

CustomersEdit.layout = {
    breadcrumbs: [
        { title: 'Backoffice', href: '/admin' },
        { title: 'Clientes', href: index() },
        { title: 'Ficha', href: '#' },
    ],
};
