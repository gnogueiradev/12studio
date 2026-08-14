import { Head, Link, router } from '@inertiajs/react';
import { Search, Tag } from 'lucide-react';
import { useEffect, useState } from 'react';
import { AdminTable } from '@/components/admin/admin-table';
import type { Column } from '@/components/admin/admin-table';
import { CategoryCreateDialog } from '@/components/admin/category-create-dialog';
import { ConfirmDialog } from '@/components/admin/confirm-dialog';
import { FilterChip } from '@/components/admin/filter-chip';
import { PageHeader } from '@/components/admin/page-header';
import { StatusBadge } from '@/components/admin/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { label } from '@/lib/options';
import { cn } from '@/lib/utils';
import { destroy, edit, index, restaurar } from '@/routes/admin/categorias';
import type { CategoryRow } from '@/types/catalog';
import { CATEGORY_STATUSES } from '@/types/catalog';

type Filters = {
    search: string;
    status: string;
};

type Props = {
    categories: CategoryRow[];
    filters: Filters;
    /** Contagem por estado, já sem o filtro de estado aplicado. */
    statusCounts: Record<string, number>;
};

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

export default function CategoriesIndex({
    categories,
    filters,
    statusCounts,
}: Props) {
    const [search, setSearch] = useState(filters.search);
    const [creating, setCreating] = useState(false);
    const [archiving, setArchiving] = useState<CategoryRow | null>(null);

    const applyFilters = (changes: Partial<Filters>) =>
        visit({ ...filters, search, ...changes });

    /*
     * Pesquisa ao vivo, como nos produtos. A guarda `search === filters.search`
     * trava o pedido na montagem e depois de cada resposta — sem ela a página
     * que volta do servidor voltava a disparar a pesquisa que a produziu.
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

    const totalCategories = Object.values(statusCounts).reduce(
        (sum, count) => sum + count,
        0,
    );

    const columns: Column<CategoryRow>[] = [
        {
            key: 'name',
            header: 'Nome',
            cell: (category) => (
                <div className="flex items-center gap-3">
                    {/*
                     * Ícone decorativo: a cor da categoria é uma pista, não a
                     * informação — essa está no nome, logo ao lado. Por isso
                     * fica `aria-hidden` e não precisa de mínimo de contraste.
                     *
                     * A cor pinta o FUNDO e não o traço do ícone. Enquanto a
                     * paleta era fechada, o traço num dos sete tons lia-se
                     * sempre; com hex livre, um tom claro apagava o ícone no
                     * tema claro e um escuro apagava-o no escuro. Como fundo com
                     * contorno — o padrão do ColorSwatch — vê-se sempre, seja
                     * qual for o tom.
                     */}
                    <span
                        aria-hidden
                        className="grid size-8 shrink-0 place-items-center rounded-lg border border-border bg-secondary"
                        style={
                            category.color === null
                                ? undefined
                                : { backgroundColor: category.color }
                        }
                    >
                        {category.color === null && (
                            <Tag className="size-4 text-gold" />
                        )}
                    </span>
                    <div className="min-w-0">
                        <span className="block font-medium">
                            {category.name}
                        </span>
                        {category.description !== null && (
                            <span className="block truncate text-xs text-muted-foreground">
                                {category.description}
                            </span>
                        )}
                    </div>
                </div>
            ),
        },
        {
            key: 'slug',
            header: 'Slug',
            className: 'font-mono text-xs text-muted-foreground',
            cell: (category) => category.slug,
        },
        {
            key: 'products',
            header: 'Produtos',
            className: 'text-right tabular-nums',
            cell: (category) => category.productsCount,
        },
        {
            key: 'status',
            header: 'Estado',
            cell: (category) => (
                <StatusBadge
                    value={category.status}
                    label={label(CATEGORY_STATUSES, category.status)}
                />
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
                    {category.status === 'archived' ? (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() =>
                                router.patch(restaurar(category.id).url, {
                                    preserveScroll: true,
                                })
                            }
                        >
                            Restaurar
                        </Button>
                    ) : (
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
            <div className="flex h-full w-full max-w-[1400px] flex-1 flex-col gap-4 p-6 pb-10">
                <PageHeader
                    title="Categorias"
                    description="Como os produtos aparecem organizados na loja."
                >
                    <Button
                        className="rounded-full"
                        onClick={() => setCreating(true)}
                    >
                        Nova categoria
                    </Button>
                </PageHeader>

                <div className="flex flex-wrap items-center gap-2">
                    <div className="relative min-w-60 flex-1 sm:max-w-85">
                        <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            type="search"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Nome ou slug"
                            aria-label="Procurar categorias"
                            className="pl-9"
                        />
                    </div>

                    <span className="ml-auto text-xs text-muted-foreground">
                        {categories.length}{' '}
                        {categories.length === 1 ? 'categoria' : 'categorias'}
                    </span>
                </div>

                <div className="flex flex-wrap gap-2 border-b border-border/60 pb-3.5">
                    <FilterChip
                        text="Todas"
                        count={totalCategories}
                        active={filters.status === ''}
                        onClick={() => applyFilters({ status: '' })}
                    />
                    {CATEGORY_STATUSES.map((status) => (
                        <FilterChip
                            key={status.value}
                            text={status.chipLabel}
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
                    rows={categories}
                    rowKey={(category) => category.id}
                    rowClassName={(category) =>
                        cn(category.status === 'archived' && 'opacity-60')
                    }
                    empty="Nenhuma categoria com estes filtros."
                />
            </div>

            <CategoryCreateDialog open={creating} onOpenChange={setCreating} />

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
                            preserveScroll: true,
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
