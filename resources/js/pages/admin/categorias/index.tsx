import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { AdminTable } from '@/components/admin/admin-table';
import type { Column } from '@/components/admin/admin-table';
import { ConfirmDialog } from '@/components/admin/confirm-dialog';
import { PageHeader } from '@/components/admin/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { create, destroy, edit, index } from '@/routes/admin/categorias';
import type { CategoryRow } from '@/types/catalog';

type Props = {
    categories: CategoryRow[];
};

export default function CategoriesIndex({ categories }: Props) {
    const [archiving, setArchiving] = useState<CategoryRow | null>(null);

    const columns: Column<CategoryRow>[] = [
        {
            key: 'name',
            header: 'Nome',
            cell: (category) => (
                <span className="font-medium">{category.name}</span>
            ),
        },
        {
            key: 'slug',
            header: 'Slug',
            className: 'text-muted-foreground',
            cell: (category) => category.slug,
        },
        {
            key: 'products',
            header: 'Produtos',
            cell: (category) => category.productsCount,
        },
        {
            key: 'status',
            header: 'Estado',
            cell: (category) => (
                <Badge variant={category.active ? 'default' : 'secondary'}>
                    {category.active ? 'Visível' : 'Arquivada'}
                </Badge>
            ),
        },
        {
            key: 'actions',
            header: '',
            className: 'text-right',
            cell: (category) => (
                <div className="flex justify-end gap-2">
                    <Button variant="outline" size="sm" asChild>
                        <Link href={edit(category.id)}>Editar</Link>
                    </Button>
                    {category.active && (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => setArchiving(category)}
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
            <Head title="Categorias" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <PageHeader title="Categorias">
                    <Button asChild>
                        <Link href={create()}>Nova categoria</Link>
                    </Button>
                </PageHeader>

                <AdminTable
                    columns={columns}
                    rows={categories}
                    rowKey={(category) => category.id}
                    empty="Ainda não há categorias — cria a primeira."
                />
            </div>

            <ConfirmDialog
                open={archiving !== null}
                onOpenChange={(open) => !open && setArchiving(null)}
                title="Arquivar categoria"
                description={
                    <>
                        A categoria <strong>{archiving?.name}</strong> deixa de
                        aparecer na loja. Os produtos associados mantêm-se.
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

CategoriesIndex.layout = {
    breadcrumbs: [
        { title: 'Backoffice', href: '/admin' },
        { title: 'Categorias', href: index() },
    ],
};
