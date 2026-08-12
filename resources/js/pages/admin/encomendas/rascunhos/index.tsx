import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { AdminTable } from '@/components/admin/admin-table';
import type { Column } from '@/components/admin/admin-table';
import { ConfirmDialog } from '@/components/admin/confirm-dialog';
import { PageHeader } from '@/components/admin/page-header';
import { Button } from '@/components/ui/button';
import { formatCents } from '@/lib/money';
import { index } from '@/routes/admin/encomendas';
import { destroy, edit } from '@/routes/admin/encomendas/rascunhos';
import type { OrderDraftRow } from '@/types/order';

type Props = {
    drafts: OrderDraftRow[];
};

export default function OrderDraftsIndex({ drafts }: Props) {
    const [deleting, setDeleting] = useState<OrderDraftRow | null>(null);

    const columns: Column<OrderDraftRow>[] = [
        {
            key: 'customer',
            header: 'Cliente',
            cell: (draft) =>
                draft.customerName === null ? (
                    <span className="text-muted-foreground">Sem nome</span>
                ) : (
                    <span className="font-medium">{draft.customerName}</span>
                ),
        },
        {
            key: 'items',
            header: 'Artigos',
            className: 'text-muted-foreground',
            cell: (draft) =>
                draft.itemsCount === 1
                    ? '1 artigo'
                    : `${draft.itemsCount} artigos`,
        },
        {
            key: 'total',
            header: 'Total',
            className: 'tabular-nums',
            cell: (draft) => formatCents(draft.totalCents),
        },
        {
            key: 'updated',
            header: 'Guardado',
            className: 'text-muted-foreground tabular-nums',
            cell: (draft) => draft.updatedAt,
        },
        {
            key: 'actions',
            header: '',
            className: 'text-right',
            cell: (draft) => (
                <div className="flex justify-end gap-2">
                    <Button variant="outline" size="sm" asChild>
                        <Link href={edit(draft.id)}>Retomar</Link>
                    </Button>
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => setDeleting(draft)}
                    >
                        Apagar
                    </Button>
                </div>
            ),
        },
    ];

    return (
        <>
            <Head title="Rascunhos" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Rascunhos"
                    description="Encomendas manuais guardadas a meio. Não têm número nem descontam stock enquanto não forem criadas."
                />

                <AdminTable
                    columns={columns}
                    rows={drafts}
                    rowKey={(draft) => draft.id}
                    empty="Sem rascunhos guardados."
                    rowHref={(draft) => edit(draft.id).url}
                />
            </div>

            <ConfirmDialog
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
                title="Apagar rascunho"
                description="O que está escrito perde-se. Nenhuma encomenda é afetada — um rascunho nunca chegou a ser uma."
                confirmLabel="Apagar"
                destructive
                onConfirm={() => {
                    if (deleting) {
                        router.delete(destroy(deleting.id).url, {
                            onFinish: () => setDeleting(null),
                        });
                    }
                }}
            />
        </>
    );
}

OrderDraftsIndex.layout = {
    breadcrumbs: [
        { title: 'Backoffice', href: '/admin' },
        { title: 'Encomendas', href: index() },
        { title: 'Rascunhos', href: '#' },
    ],
};
