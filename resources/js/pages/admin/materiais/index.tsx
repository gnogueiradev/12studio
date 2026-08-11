import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { AdminTable } from '@/components/admin/admin-table';
import type { Column } from '@/components/admin/admin-table';
import { ConfirmDialog } from '@/components/admin/confirm-dialog';
import { PageHeader } from '@/components/admin/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatCents } from '@/lib/money';
import { index as coresIndex } from '@/routes/admin/cores';
import { create, destroy, edit, index } from '@/routes/admin/materiais';
import type { MaterialRow } from '@/types/catalog';

type Props = {
    materials: MaterialRow[];
};

export default function MaterialsIndex({ materials }: Props) {
    const [archiving, setArchiving] = useState<MaterialRow | null>(null);

    const columns: Column<MaterialRow>[] = [
        {
            key: 'name',
            header: 'Material',
            cell: (material) => (
                <span className="font-medium">{material.name}</span>
            ),
        },
        {
            key: 'price',
            header: 'Preço por kg',
            cell: (material) => formatCents(material.pricePerKgCents),
        },
        {
            key: 'colors',
            header: 'Cores',
            cell: (material) => material.colorsCount,
        },
        {
            key: 'status',
            header: 'Estado',
            cell: (material) => (
                <Badge variant={material.active ? 'default' : 'secondary'}>
                    {material.active ? 'Disponível' : 'Arquivado'}
                </Badge>
            ),
        },
        {
            key: 'actions',
            header: '',
            className: 'text-right',
            cell: (material) => (
                <div className="flex justify-end gap-2">
                    <Button variant="outline" size="sm" asChild>
                        <Link href={edit(material.id)}>Editar</Link>
                    </Button>
                    {material.active && (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => setArchiving(material)}
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
            <Head title="Materiais" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Materiais"
                    description="Cada material tem o seu preço por quilo — é daqui que sai o custo de cada peça impressa."
                >
                    <Button variant="outline" asChild>
                        <Link href={coresIndex()}>Cores</Link>
                    </Button>
                    <Button asChild>
                        <Link href={create()}>Novo material</Link>
                    </Button>
                </PageHeader>

                <AdminTable
                    columns={columns}
                    rows={materials}
                    rowKey={(material) => material.id}
                    empty="Ainda não há materiais — cria o primeiro (PLA, PETG…) para lhe poderes juntar cores."
                />
            </div>

            <ConfirmDialog
                open={archiving !== null}
                onOpenChange={(open) => !open && setArchiving(null)}
                title="Arquivar material"
                description={
                    <>
                        O material <strong>{archiving?.name}</strong> deixa de
                        aparecer ao criar variantes. As cores e as variantes que
                        já o usam mantêm-se intactas.
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

MaterialsIndex.layout = {
    breadcrumbs: [
        { title: 'Backoffice', href: '/admin' },
        { title: 'Materiais', href: index() },
    ],
};
