import { Head, Link, router } from '@inertiajs/react';
import { Box } from 'lucide-react';
import { useEffect, useState } from 'react';
import { PageHeader } from '@/components/admin/page-header';
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
import { create, edit, index } from '@/routes/admin/produtos';
import type { ProductRow } from '@/types/catalog';
import { PRODUCT_STATUSES } from '@/types/catalog';
import type { Paginated } from '@/types/pagination';

type Filters = {
    search: string;
    status: string;
    /*
     * Categoria, produção e etiqueta deixaram de ter controlo na página — o
     * desenho ficou só com as abas e a pesquisa. Continuam a ser respeitados
     * quando vêm no URL, e por isso viajam em cada visita.
     */
    category_id: string;
    fulfillment_mode: string;
    /** Slug da etiqueta, ou '' sem filtro. */
    tag: string;
    /** '8', '20' ou '50'; '' são os 20 por omissão do servidor. */
    per_page: string;
};

type Props = {
    products: Paginated<ProductRow>;
    filters: Filters;
    /** Contagem por estado, já sem o filtro de estado aplicado. */
    statusCounts: Record<string, number>;
};

/** Tempo de silêncio antes de a pesquisa ir ao servidor. */
const SEARCH_DEBOUNCE_MS = 350;

const PAGE_SIZES = ['8', '20', '50'];

/** Filtros vazios saem da query string em vez de irem como `?status=`. */
function visit(filters: Filters, page?: number) {
    router.get(
        index().url,
        {
            ...Object.fromEntries(
                Object.entries(filters).filter(([, value]) => value !== ''),
            ),
            ...(page !== undefined && page > 1 ? { page } : {}),
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function plural(count: number, one: string, many: string): string {
    return `${count} ${count === 1 ? one : many}`;
}

/**
 * A linha de apoio do produto: referência · variantes · o que falta ou o
 * prazo. Um produto sem preço não tem prazo que interesse — o que ali importa
 * dizer é o que o impede de ir para a montra.
 */
function productMeta(product: ProductRow): string {
    const variants =
        product.variantsCount === 0
            ? 'sem variantes'
            : plural(product.variantsCount, 'variante', 'variantes');

    let readiness: string;

    if (product.priceCents === null) {
        readiness = 'falta preço';
    } else if (product.fulfillmentMode === 'in_stock') {
        readiness = `${product.readyStock} un. prontas`;
    } else if (product.productionTimeDays === null) {
        readiness = 'prazo por definir';
    } else {
        readiness = plural(product.productionTimeDays, 'dia', 'dias');
    }

    return [product.sku ?? 'sem referência', variants, readiness].join(' · ');
}

/**
 * Os números de página a mostrar: todos até sete, e daí para cima a primeira,
 * a última e as vizinhas da atual, com reticências nos buracos — senão um
 * catálogo grande a 8 por página empurrava os botões para fora do ecrã.
 */
function pageWindow(current: number, last: number): (number | null)[] {
    if (last <= 7) {
        return Array.from({ length: last }, (_, i) => i + 1);
    }

    const pages = [1, current - 1, current, current + 1, last].filter(
        (page, i, all) => page >= 1 && page <= last && all.indexOf(page) === i,
    );

    return pages.flatMap((page, i) =>
        i > 0 && page - pages[i - 1] > 1 ? [null, page] : [page],
    );
}

export default function ProductsIndex({
    products,
    filters,
    statusCounts,
}: Props) {
    const [search, setSearch] = useState(filters.search);

    const applyFilters = (changes: Partial<Filters>) =>
        visit({ ...filters, search, ...changes });

    const goToPage = (page: number) => visit({ ...filters, search }, page);

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

    const tabs = [
        { value: '', text: 'Todos', count: totalProducts },
        ...PRODUCT_STATUSES.map((status) => ({
            value: status.value,
            text: status.chipLabel,
            count: statusCounts[status.value] ?? 0,
        })),
    ];

    const current = products.current_page;
    const last = products.last_page;

    return (
        <>
            <Head title="Produtos" />
            <div className="flex h-full w-full max-w-[1400px] flex-1 flex-col p-6 pb-10">
                <PageHeader
                    title="Produtos"
                    description="O catálogo, com variantes de material e cor."
                >
                    <Button asChild className="rounded-full">
                        <Link href={create()}>Novo produto</Link>
                    </Button>
                </PageHeader>

                <div className="mt-5 flex flex-wrap items-center gap-x-5 gap-y-3.5 border-b border-border pb-3">
                    <div className="flex flex-wrap gap-4.5">
                        {tabs.map((tab) => {
                            const active = filters.status === tab.value;

                            return (
                                <button
                                    key={tab.value || 'all'}
                                    type="button"
                                    aria-pressed={active}
                                    onClick={() =>
                                        applyFilters({ status: tab.value })
                                    }
                                    className={cn(
                                        'border-b-2 pt-0.5 pb-1.5 text-[13.5px] transition-colors',
                                        active
                                            ? 'border-gold font-semibold text-foreground'
                                            : 'border-transparent text-muted-foreground hover:text-foreground',
                                    )}
                                >
                                    {tab.text}{' '}
                                    <span className="font-normal text-muted-foreground tabular-nums">
                                        {tab.count}
                                    </span>
                                </button>
                            );
                        })}
                    </div>

                    <Input
                        type="search"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        placeholder="Procurar"
                        aria-label="Procurar produtos por nome ou referência"
                        className="ml-auto h-auto w-full rounded-[9px] bg-secondary/30 px-3 py-2 text-[13px] focus-visible:border-gold sm:w-50 md:text-[13px]"
                    />
                </div>

                {products.data.length === 0 ? (
                    <p className="py-9 text-center text-[13px] text-muted-foreground">
                        Nenhum produto com estes filtros.
                    </p>
                ) : (
                    <ul>
                        {products.data.map((product) => (
                            <li key={product.id}>
                                <Link
                                    href={edit(product.id)}
                                    className="flex w-full flex-wrap items-center gap-x-3.5 gap-y-2 border-b border-border/60 px-0.5 py-2.75 text-left transition-colors hover:bg-secondary/40 focus-visible:bg-secondary/40 focus-visible:outline-none"
                                >
                                    <span className="grid size-9.5 flex-none place-items-center overflow-hidden rounded-[9px] bg-secondary text-muted-foreground/60">
                                        {product.imageUrl === null ? (
                                            <Box
                                                className="size-4.5"
                                                strokeWidth={1.2}
                                            />
                                        ) : (
                                            <img
                                                src={product.imageUrl}
                                                alt=""
                                                className="size-full object-cover"
                                            />
                                        )}
                                    </span>

                                    <span className="min-w-0 flex-[1_1_150px]">
                                        <span className="flex flex-wrap items-center gap-2">
                                            <span className="text-sm font-medium text-pretty">
                                                {product.name}
                                            </span>
                                            {product.status !== 'active' && (
                                                <span className="rounded-full border border-border px-2 py-0.5 text-[10.5px] text-muted-foreground">
                                                    {label(
                                                        PRODUCT_STATUSES,
                                                        product.status,
                                                    )}
                                                </span>
                                            )}
                                        </span>
                                        <span className="mt-0.5 block text-[11.5px] text-muted-foreground">
                                            {productMeta(product)}
                                        </span>
                                    </span>

                                    <span
                                        className={cn(
                                            'flex min-w-17.5 flex-none items-center justify-end gap-2 text-right text-sm font-semibold tabular-nums',
                                            product.priceCents === null &&
                                                'text-muted-foreground',
                                        )}
                                    >
                                        {product.priceCents === null
                                            ? '—'
                                            : formatCents(product.priceCents)}
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}

                <div className="mt-4.5 flex flex-wrap items-center gap-x-4 gap-y-2.5 text-[12.5px] text-muted-foreground">
                    <span className="tabular-nums">
                        {products.total === 0
                            ? '0 de 0'
                            : `${products.from}–${products.to} de ${products.total}`}
                    </span>

                    <label className="flex items-center gap-2">
                        por página
                        <Select
                            value={filters.per_page || '20'}
                            onValueChange={(value) =>
                                applyFilters({
                                    per_page: value === '20' ? '' : value,
                                })
                            }
                        >
                            <SelectTrigger
                                size="sm"
                                className="h-7 gap-1.5 rounded-lg px-2.5 text-[12.5px] text-foreground"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {PAGE_SIZES.map((size) => (
                                    <SelectItem key={size} value={size}>
                                        {size}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </label>

                    {last > 1 && (
                        <nav
                            aria-label="Páginas"
                            className="ml-auto flex items-center gap-1"
                        >
                            <button
                                type="button"
                                disabled={current === 1}
                                onClick={() => goToPage(current - 1)}
                                className="rounded-lg border border-border px-3 py-1.5 text-foreground transition-colors hover:border-muted-foreground disabled:pointer-events-none disabled:text-muted-foreground/60"
                            >
                                Anterior
                            </button>
                            {pageWindow(current, last).map((page, i) =>
                                page === null ? (
                                    <span
                                        key={`gap-${i}`}
                                        className="px-1"
                                        aria-hidden
                                    >
                                        …
                                    </span>
                                ) : (
                                    <button
                                        key={page}
                                        type="button"
                                        aria-current={
                                            page === current
                                                ? 'page'
                                                : undefined
                                        }
                                        onClick={() => goToPage(page)}
                                        className={cn(
                                            'min-w-7.5 rounded-lg px-2 py-1.5 tabular-nums transition-colors',
                                            page === current
                                                ? 'bg-primary-hover text-primary-foreground'
                                                : 'hover:text-foreground',
                                        )}
                                    >
                                        {page}
                                    </button>
                                ),
                            )}
                            <button
                                type="button"
                                disabled={current === last}
                                onClick={() => goToPage(current + 1)}
                                className="rounded-lg border border-border px-3 py-1.5 text-foreground transition-colors hover:border-muted-foreground disabled:pointer-events-none disabled:text-muted-foreground/60"
                            >
                                Seguinte
                            </button>
                        </nav>
                    )}
                </div>
            </div>
        </>
    );
}

ProductsIndex.layout = {
    breadcrumbs: [
        { title: 'Backoffice', href: '/admin' },
        { title: 'Produtos', href: index() },
    ],
};
