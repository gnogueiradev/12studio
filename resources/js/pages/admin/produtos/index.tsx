import { Head, Link, router, usePage } from '@inertiajs/react';
import { Package, Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import { AdminTable } from '@/components/admin/admin-table';
import type { Column } from '@/components/admin/admin-table';
import { ConfirmDialog } from '@/components/admin/confirm-dialog';
import { FilterChip } from '@/components/admin/filter-chip';
import { PageHeader } from '@/components/admin/page-header';
import { Pagination } from '@/components/admin/pagination';
import { ProductCreateDialog } from '@/components/admin/product-create-dialog';
import { StatusBadge, StatusText } from '@/components/admin/status-badge';
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
import { cn } from '@/lib/utils';
import { destroy, edit, index, restaurar } from '@/routes/admin/produtos';
import type {
    CategoryOption,
    ColorOption,
    MaterialOption,
    ProductRow,
} from '@/types/catalog';
import { FULFILLMENT_MODES, PRODUCT_STATUSES } from '@/types/catalog';
import type { Paginated } from '@/types/pagination';
import type { PricingBreakdown } from '@/types/pricing';

type Filters = {
    search: string;
    status: string;
    category_id: string;
    fulfillment_mode: string;
};

type Props = {
    products: Paginated<ProductRow>;
    filters: Filters;
    /** Contagem por estado, já sem o filtro de estado aplicado. */
    statusCounts: Record<string, number>;
    categories: CategoryOption[];
    colors: ColorOption[];
    materials: MaterialOption[];
    defaultVatRate: number;
    /** Preço sugerido para o modal de novo produto. */
    pricingPreview: { result: PricingBreakdown | null };
};

// O Radix Select não aceita value="" — sentinela para "sem filtro".
const ALL = 'all';

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

/** 130 → "2 h 10 m"; 55 → "55 m". */
function formatMinutes(minutes: number): string {
    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;

    if (hours === 0) {
        return `${rest} m`;
    }

    return rest === 0 ? `${hours} h` : `${hours} h ${rest} m`;
}

/**
 * A segunda linha da célula do produto: referência, gramagem e tempo da
 * variante default. Um produto sem variantes não tem nenhum dos três — é o
 * rascunho a meio, e o que ali falta é a informação útil.
 */
function productMeta(product: ProductRow): string {
    if (product.variantsCount === 0) {
        return 'Sem variantes — falta preço e referência';
    }

    return [
        product.sku,
        product.filamentWeightGrams === null
            ? null
            : `${product.filamentWeightGrams} g`,
        product.printingTimeMinutes === null
            ? null
            : formatMinutes(product.printingTimeMinutes),
    ]
        .filter((part) => part !== null)
        .join(' · ');
}

export default function ProductsIndex({
    products,
    filters,
    statusCounts,
    categories,
    colors,
    materials,
    defaultVatRate,
    pricingPreview,
}: Props) {
    const [search, setSearch] = useState(filters.search);
    /*
     * `?novo=1` é o atalho "Novo produto" do painel a pedir o modal já aberto.
     * Lido do `usePage().url` e não do `window.location` para o SSR não ir
     * abaixo à procura de um `window` que lá não existe.
     */
    const { url } = usePage();
    const [creating, setCreating] = useState(() => url.includes('novo=1'));
    const [archiving, setArchiving] = useState<ProductRow | null>(null);

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

    const totalProducts = Object.values(statusCounts).reduce(
        (sum, count) => sum + count,
        0,
    );

    const columns: Column<ProductRow>[] = [
        {
            key: 'name',
            header: 'Produto',
            cell: (product) => (
                <div className="flex items-center gap-3">
                    <span className="grid size-9 flex-none place-items-center overflow-hidden rounded-lg border border-border/60 bg-secondary text-muted-foreground">
                        {product.imageUrl === null ? (
                            <Package className="size-4" />
                        ) : (
                            <img
                                src={product.imageUrl}
                                alt=""
                                className="size-full object-cover"
                            />
                        )}
                    </span>
                    <span>
                        <Link
                            href={edit(product.id)}
                            className="font-medium hover:underline"
                        >
                            {product.name}
                        </Link>
                        <span className="mt-0.5 block text-xs text-muted-foreground">
                            {productMeta(product)}
                        </span>
                    </span>
                </div>
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
            className: 'text-right tabular-nums',
            cell: (product) =>
                product.variantsCount === 0 ? (
                    <span className="text-muted-foreground">0</span>
                ) : (
                    product.variantsCount
                ),
        },
        {
            key: 'fulfillment',
            header: 'Produção',
            cell: (product) => (
                <>
                    <StatusText
                        tone={
                            product.fulfillmentMode === 'in_stock'
                                ? 'success'
                                : 'warning'
                        }
                        label={label(
                            FULFILLMENT_MODES,
                            product.fulfillmentMode,
                        )}
                    />
                    <span className="mt-0.5 block text-xs text-muted-foreground">
                        {product.fulfillmentMode === 'in_stock'
                            ? `${product.readyStock} un. prontas`
                            : product.productionTimeDays === null
                              ? 'Prazo por definir'
                              : `${product.productionTimeDays} dias`}
                    </span>
                </>
            ),
        },
        {
            key: 'price',
            header: 'Preço',
            className: 'text-right tabular-nums',
            cell: (product) =>
                product.priceCents === null ? (
                    <span className="text-muted-foreground">—</span>
                ) : (
                    formatCents(product.priceCents)
                ),
        },
        {
            key: 'status',
            header: 'Estado',
            cell: (product) => (
                <StatusBadge
                    value={product.status}
                    label={label(PRODUCT_STATUSES, product.status)}
                />
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
                    {product.status === 'archived' ? (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() =>
                                router.patch(
                                    restaurar(product.id).url,
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Restaurar
                        </Button>
                    ) : (
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
            <div className="flex h-full w-full max-w-[1400px] flex-1 flex-col gap-4 p-6 pb-10">
                <PageHeader
                    title="Produtos"
                    description="O catálogo, com variantes de material e cor."
                >
                    <Button
                        className="rounded-full"
                        onClick={() => setCreating(true)}
                    >
                        Novo produto
                    </Button>
                </PageHeader>

                <div className="flex flex-wrap items-center gap-2">
                    <div className="relative min-w-60 flex-1 sm:max-w-85">
                        <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            type="search"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Nome ou referência"
                            aria-label="Procurar produtos"
                            className="pl-9"
                        />
                    </div>

                    <Select
                        value={filters.category_id || ALL}
                        onValueChange={(value) =>
                            applyFilters({
                                category_id: value === ALL ? '' : value,
                            })
                        }
                    >
                        <SelectTrigger className="w-52">
                            <SelectValue placeholder="Categoria" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>
                                Todas as categorias
                            </SelectItem>
                            {categories.map((category) => (
                                <SelectItem
                                    key={category.id}
                                    value={String(category.id)}
                                >
                                    {category.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <Select
                        value={filters.fulfillment_mode || ALL}
                        onValueChange={(value) =>
                            applyFilters({
                                fulfillment_mode: value === ALL ? '' : value,
                            })
                        }
                    >
                        <SelectTrigger className="w-52">
                            <SelectValue placeholder="Produção" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>
                                Qualquer produção
                            </SelectItem>
                            {FULFILLMENT_MODES.map((mode) => (
                                <SelectItem key={mode.value} value={mode.value}>
                                    {mode.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <span className="ml-auto text-xs text-muted-foreground">
                        {products.total}{' '}
                        {products.total === 1 ? 'produto' : 'produtos'}
                    </span>
                </div>

                <div className="flex flex-wrap gap-2 border-b border-border/60 pb-3.5">
                    <FilterChip
                        text="Todos"
                        count={totalProducts}
                        active={filters.status === ''}
                        onClick={() => applyFilters({ status: '' })}
                    />
                    {PRODUCT_STATUSES.map((status) => (
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
                    rows={products.data}
                    rowKey={(product) => product.id}
                    rowClassName={(product) =>
                        cn(product.status === 'archived' && 'opacity-60')
                    }
                    empty="Nenhum produto com estes filtros."
                />

                <Pagination page={products} noun="produtos" />
            </div>

            <ProductCreateDialog
                open={creating}
                onOpenChange={setCreating}
                categories={categories}
                colors={colors}
                materials={materials}
                defaultVatRate={defaultVatRate}
                pricingPreview={pricingPreview}
            />

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
                            preserveScroll: true,
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
