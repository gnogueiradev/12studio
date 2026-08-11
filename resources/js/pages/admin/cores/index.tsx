import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { AdminTable } from '@/components/admin/admin-table';
import type { Column } from '@/components/admin/admin-table';
import { ColorSwatch } from '@/components/admin/color-swatch';
import { ConfirmDialog } from '@/components/admin/confirm-dialog';
import { PageHeader } from '@/components/admin/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatCents } from '@/lib/money';
import { create, destroy, edit, index } from '@/routes/admin/cores';
import { index as materiaisIndex } from '@/routes/admin/materiais';
import type { ColorRow } from '@/types/catalog';

type Props = {
    colors: ColorRow[];
};

export default function ColorsIndex({ colors }: Props) {
    const [archiving, setArchiving] = useState<ColorRow | null>(null);

    const columns: Column<ColorRow>[] = [
        {
            key: 'name',
            header: 'Cor',
            cell: (color) => (
                <span className="flex items-center gap-2">
                    <ColorSwatch hex={color.hexColor} />
                    <span className="font-medium">{color.name}</span>
                    <span className="font-mono text-xs text-muted-foreground">
                        {color.hexColor}
                    </span>
                </span>
            ),
        },
        {
            key: 'material',
            header: 'Material',
            className: 'text-muted-foreground',
            cell: (color) => color.material,
        },
        {
            key: 'price',
            header: 'Preço por kg',
            cell: (color) => (
                <>
                    {formatCents(color.effectivePricePerKgCents)}
                    {!color.hasOwnPrice && (
                        <span className="ml-2 text-xs text-muted-foreground">
                            herdado
                        </span>
                    )}
                </>
            ),
        },
        {
            key: 'variants',
            header: 'Variantes',
            cell: (color) => color.variantsCount,
        },
        {
            key: 'status',
            header: 'Estado',
            cell: (color) => (
                <Badge variant={color.isActive ? 'default' : 'secondary'}>
                    {color.isActive ? 'Disponível' : 'Arquivada'}
                </Badge>
            ),
        },
        {
            key: 'actions',
            header: '',
            className: 'text-right',
            cell: (color) => (
                <div className="flex justify-end gap-2">
                    <Button variant="outline" size="sm" asChild>
                        <Link href={edit(color.id)}>Editar</Link>
                    </Button>
                    {color.isActive && (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => setArchiving(color)}
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
            <Head title="Cores" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Cores"
                    description="Cada cor pertence a um material. Herda o preço por quilo dele, a não ser que lhe dês um próprio."
                >
                    <Button variant="outline" asChild>
                        <Link href={materiaisIndex()}>Materiais</Link>
                    </Button>
                    <Button asChild>
                        <Link href={create()}>Nova cor</Link>
                    </Button>
                </PageHeader>

                <AdminTable
                    columns={columns}
                    rows={colors}
                    rowKey={(color) => color.id}
                    empty="Ainda não há cores. Cria um material primeiro e depois as cores em que o imprimes."
                />
            </div>

            <ConfirmDialog
                open={archiving !== null}
                onOpenChange={(open) => !open && setArchiving(null)}
                title="Arquivar cor"
                description={
                    <>
                        A cor <strong>{archiving?.name}</strong> deixa de
                        aparecer ao criar variantes. As{' '}
                        {archiving?.variantsCount ?? 0} variantes que já a usam
                        mantêm-se.
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

ColorsIndex.layout = {
    breadcrumbs: [
        { title: 'Backoffice', href: '/admin' },
        { title: 'Cores', href: index() },
    ],
};
