import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { AdminTable } from '@/components/admin/admin-table';
import type { Column } from '@/components/admin/admin-table';
import { ConfirmDialog } from '@/components/admin/confirm-dialog';
import { PageHeader } from '@/components/admin/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { label } from '@/lib/options';
import { create, destroy, edit, index } from '@/routes/admin/produtos';
import type { ProductRow } from '@/types/catalog';
import { FULFILLMENT_MODES, PRODUCT_STATUSES } from '@/types/catalog';

type Props = {
    products: ProductRow[];
};

export default function ProductsIndex({ products }: Props) {
    const [archiving, setArchiving] = useState<ProductRow | null>(null);

    const columns: Column<ProductRow>[] = [
        {
            key: 'name',
            header: 'Nome',
            cell: (product) => (
                <>
                    <span className="font-medium">{product.name}</span>
                    {product.featured && (
                        <Badge variant="outline" className="ml-2">
                            Destaque
                        </Badge>
                    )}
                </>
            ),
        },
        {
            key: 'category',
            header: 'Categoria',
            className: 'text-muted-foreground',
            cell: (product) => product.category ?? '—',
        },
        {
            key: 'variants',
            header: 'Variantes',
            className: 'text-muted-foreground',
            cell: (product) => product.variantsCount,
        },
        {
            key: 'fulfillment',
            header: 'Produção',
            className: 'text-muted-foreground',
            cell: (product) =>
                label(FULFILLMENT_MODES, product.fulfillmentMode),
        },
        {
            key: 'status',
            header: 'Estado',
            cell: (product) => (
                <Badge
                    variant={
                        product.status === 'active' ? 'default' : 'secondary'
                    }
                >
                    {label(PRODUCT_STATUSES, product.status)}
                </Badge>
            ),
        },
        {
            key: 'actions',
            header: '',
            className: 'text-right',
            cell: (product) => (
                <div className="flex justify-end gap-2">
                    <Button variant="outline" size="sm" asChild>
                        <Link href={edit(product.id)}>Editar</Link>
                    </Button>
                    {product.status !== 'archived' && (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => setArchiving(product)}
                        >
                            Arquivar
                        </Button>
                    )}
                </div>
            ),
        },
    ];

    return (
        <>
            <Head title="Produtos" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <PageHeader title="Produtos">
                    <Button asChild>
                        <Link href={create()}>Novo produto</Link>
                    </Button>
                </PageHeader>

                <AdminTable
                    columns={columns}
                    rows={products}
                    rowKey={(product) => product.id}
                    empty="Ainda não há produtos — cria o primeiro."
                />
            </div>

            <ConfirmDialog
                open={archiving !== null}
                onOpenChange={(open) => !open && setArchiving(null)}
                title="Arquivar produto"
                description={
                    <>
                        O produto <strong>{archiving?.name}</strong> sai da
                        montra. As variantes, o stock e o histórico de
                        encomendas mantêm-se intactos.
                    </>
                }
                confirmLabel="Arquivar"
                destructive
                onConfirm={() => {
                    if (archiving) {
                        router.delete(destroy(archiving.id).url, {
                            onFinish: () => setArchiving(null),
                        });
                    }
                }}
            />
        </>
    );
}

ProductsIndex.layout = {
    breadcrumbs: [
        { title: 'Backoffice', href: '/admin' },
        { title: 'Produtos', href: index() },
    ],
};
