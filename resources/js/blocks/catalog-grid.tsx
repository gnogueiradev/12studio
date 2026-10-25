import { InfiniteScroll, Link, router, usePage } from '@inertiajs/react';
import { Check, Search, SlidersHorizontal, X } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { ProductCard } from '@/components/store/product-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetFooter,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { useLocalizedUrl, useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type {
    CatalogData,
    ProductCardData,
    ScrollData,
    StoreBlock,
} from '@/types/store';
import { BlockSection } from './section';

type Filters = CatalogData['filters'];

const SORTS = ['destaques', 'novidades', 'preco-asc', 'preco-desc'] as const;

/** Os filtros no formato do URL: listas separadas por vírgulas, sem vazios. */
function toQuery(filters: Filters): Record<string, string | number> {
    const query: Record<string, string | number> = {};

    if (filters.q) {
        query.q = filters.q;
    }

    if (filters.cor.length) {
        query.cor = filters.cor.join(',');
    }

    if (filters.material.length) {
        query.material = filters.material.join(',');
    }

    if (filters.preco_min !== null) {
        query.preco_min = filters.preco_min;
    }

    if (filters.preco_max !== null) {
        query.preco_max = filters.preco_max;
    }

    if (filters.disponibilidade) {
        query.disponibilidade = filters.disponibilidade;
    }

    if (filters.ordem !== 'destaques') {
        query.ordem = filters.ordem;
    }

    return query;
}

function activeCount(filters: Filters): number {
    return (
        filters.cor.length +
        filters.material.length +
        (filters.preco_min !== null || filters.preco_max !== null ? 1 : 0) +
        (filters.disponibilidade ? 1 : 0)
    );
}

function FilterSection({
    title,
    children,
}: {
    title: string;
    children: ReactNode;
}) {
    return (
        <fieldset className="border-b border-border/60 pb-5">
            <legend className="mb-3 text-sm font-semibold">{title}</legend>
            {children}
        </fieldset>
    );
}

/**
 * Os filtros, iguais na coluna (desktop) e no painel (telemóvel). Cada
 * mudança é uma visita com o URL novo: os filtros partilham-se por link e o
 * "voltar" do browser funciona.
 */
function FilterPanel({
    catalog,
    apply,
}: {
    catalog: CatalogData;
    apply: (next: Partial<Filters>) => void;
}) {
    const t = useT();
    const localized = useLocalizedUrl();
    const { filters, facets } = catalog;
    const [price, setPrice] = useState({
        min: filters.preco_min?.toString() ?? '',
        max: filters.preco_max?.toString() ?? '',
    });

    const toggle = (key: 'cor' | 'material', id: number) =>
        apply({
            [key]: filters[key].includes(id)
                ? filters[key].filter((value) => value !== id)
                : [...filters[key], id],
        });

    const applyPrice = (event?: React.FormEvent) => {
        event?.preventDefault();
        const parse = (value: string) =>
            value.trim() === '' || Number.isNaN(Number(value))
                ? null
                : Math.max(0, Math.round(Number(value)));

        apply({ preco_min: parse(price.min), preco_max: parse(price.max) });
    };

    return (
        <div className="flex flex-col gap-5">
            {facets.categories.length > 1 && (
                <FilterSection title={t('catalog.categories')}>
                    <ul className="flex flex-col gap-1.5 text-sm">
                        <li>
                            <Link
                                href={localized('/produtos')}
                                className={cn(
                                    'text-muted-foreground hover:text-foreground',
                                    catalog.category === null &&
                                        'font-medium text-foreground',
                                )}
                            >
                                {t('catalog.all')}
                            </Link>
                        </li>
                        {facets.categories.map((category) => (
                            <li key={category.slug}>
                                <Link
                                    href={localized(
                                        `/categoria/${category.slug}`,
                                    )}
                                    aria-current={
                                        catalog.category === category.slug
                                            ? 'page'
                                            : undefined
                                    }
                                    className="text-muted-foreground hover:text-foreground aria-[current=page]:font-medium aria-[current=page]:text-foreground"
                                >
                                    {category.name}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </FilterSection>
            )}

            {facets.colors.length > 0 && (
                <FilterSection title={t('catalog.colors')}>
                    <ul className="flex flex-wrap gap-2">
                        {facets.colors.map((color) => {
                            const active = filters.cor.includes(color.id);

                            return (
                                <li key={color.id}>
                                    <button
                                        type="button"
                                        onClick={() => toggle('cor', color.id)}
                                        aria-pressed={active}
                                        title={color.name}
                                        className={cn(
                                            'flex size-8 items-center justify-center rounded-full border border-border ring-offset-2 ring-offset-background transition-shadow',
                                            active && 'ring-2 ring-foreground',
                                        )}
                                        style={{ backgroundColor: color.hex }}
                                    >
                                        <span className="sr-only">
                                            {color.name}
                                        </span>
                                        {active && (
                                            <Check
                                                aria-hidden="true"
                                                className="size-4 text-background mix-blend-difference"
                                            />
                                        )}
                                    </button>
                                </li>
                            );
                        })}
                    </ul>
                </FilterSection>
            )}

            {facets.materials.length > 1 && (
                <FilterSection title={t('catalog.materials')}>
                    <ul className="flex flex-wrap gap-2">
                        {facets.materials.map((material) => (
                            <li key={material.id}>
                                <button
                                    type="button"
                                    onClick={() =>
                                        toggle('material', material.id)
                                    }
                                    aria-pressed={filters.material.includes(
                                        material.id,
                                    )}
                                    className="rounded-full border border-border px-3 py-1 text-sm transition-colors hover:border-foreground/40 aria-pressed:border-foreground aria-pressed:bg-foreground aria-pressed:text-background"
                                >
                                    {material.name}
                                </button>
                            </li>
                        ))}
                    </ul>
                </FilterSection>
            )}

            {facets.price.max > facets.price.min && (
                <FilterSection title={t('catalog.price')}>
                    <form
                        onSubmit={applyPrice}
                        className="flex items-center gap-2"
                    >
                        <Input
                            type="number"
                            inputMode="numeric"
                            min={0}
                            aria-label={t('catalog.price_min')}
                            placeholder={String(facets.price.min)}
                            value={price.min}
                            onChange={(event) =>
                                setPrice({ ...price, min: event.target.value })
                            }
                            onBlur={() => applyPrice()}
                        />
                        <span className="text-muted-foreground">–</span>
                        <Input
                            type="number"
                            inputMode="numeric"
                            min={0}
                            aria-label={t('catalog.price_max')}
                            placeholder={String(facets.price.max)}
                            value={price.max}
                            onChange={(event) =>
                                setPrice({ ...price, max: event.target.value })
                            }
                            onBlur={() => applyPrice()}
                        />
                    </form>
                </FilterSection>
            )}

            <FilterSection title={t('catalog.availability')}>
                <div className="flex flex-col gap-2 text-sm">
                    {(
                        [
                            [null, t('catalog.any')],
                            ['pronto', t('catalog.ready')],
                            ['encomenda', t('catalog.to_order')],
                        ] as const
                    ).map(([value, label]) => (
                        <label
                            key={label}
                            className="flex cursor-pointer items-center gap-2"
                        >
                            <input
                                type="radio"
                                name="disponibilidade"
                                className="size-4 accent-foreground"
                                checked={filters.disponibilidade === value}
                                onChange={() =>
                                    apply({ disponibilidade: value })
                                }
                            />
                            {label}
                        </label>
                    ))}
                </div>
            </FilterSection>
        </div>
    );
}

type Data = { empty_title: string; empty_text: string };

export function CatalogGridBlock({ block }: { block: StoreBlock }) {
    const data = block.data as Data;
    const t = useT();
    const localized = useLocalizedUrl();
    const { catalog, products } = usePage().props as unknown as {
        catalog: CatalogData;
        products: ScrollData<ProductCardData>;
    };
    const [query, setQuery] = useState(catalog.filters.q);
    const [sheetOpen, setSheetOpen] = useState(false);

    const filters = catalog.filters;
    const count = activeCount(filters);
    const side = block.variant !== 'filters_top';

    const apply = (next: Partial<Filters>) =>
        router.get(catalog.path, toQuery({ ...filters, ...next }), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            reset: ['products'],
        });

    const clear = () => {
        setQuery('');
        router.get(
            catalog.path,
            filters.ordem !== 'destaques' ? { ordem: filters.ordem } : {},
            { preserveScroll: true, reset: ['products'] },
        );
    };

    const colorName = (id: number) =>
        catalog.facets.colors.find((color) => color.id === id)?.name ?? '';
    const materialName = (id: number) =>
        catalog.facets.materials.find((material) => material.id === id)?.name ??
        '';

    const chips: { label: string; remove: () => void }[] = [
        ...(filters.q
            ? [
                  {
                      label: t('catalog.search_for', { q: filters.q }),
                      remove: () => {
                          setQuery('');
                          apply({ q: '' });
                      },
                  },
              ]
            : []),
        ...filters.cor.map((id) => ({
            label: colorName(id),
            remove: () =>
                apply({ cor: filters.cor.filter((value) => value !== id) }),
        })),
        ...filters.material.map((id) => ({
            label: materialName(id),
            remove: () =>
                apply({
                    material: filters.material.filter((value) => value !== id),
                }),
        })),
        ...(filters.preco_min !== null || filters.preco_max !== null
            ? [
                  {
                      label: `${filters.preco_min ?? catalog.facets.price.min}–${filters.preco_max ?? catalog.facets.price.max} €`,
                      remove: () => apply({ preco_min: null, preco_max: null }),
                  },
              ]
            : []),
        ...(filters.disponibilidade
            ? [
                  {
                      label:
                          filters.disponibilidade === 'pronto'
                              ? t('catalog.ready')
                              : t('catalog.to_order'),
                      remove: () => apply({ disponibilidade: null }),
                  },
              ]
            : []),
    ];

    const panel = <FilterPanel catalog={catalog} apply={apply} />;

    return (
        <BlockSection style={block.style}>
            <div className="flex flex-wrap items-center gap-3 border-b border-border/60 pb-4">
                <form
                    role="search"
                    className="relative min-w-0 flex-1 basis-56"
                    onSubmit={(event) => {
                        event.preventDefault();
                        apply({ q: query.trim() });
                    }}
                >
                    <Search
                        aria-hidden="true"
                        className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <Input
                        type="search"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder={t('catalog.search_placeholder')}
                        aria-label={t('catalog.search_placeholder')}
                        className="pl-9"
                    />
                </form>

                <Sheet open={sheetOpen} onOpenChange={setSheetOpen}>
                    <SheetTrigger asChild>
                        <Button
                            variant="outline"
                            className={cn('gap-2', side && 'lg:hidden')}
                        >
                            <SlidersHorizontal aria-hidden="true" />
                            {t('catalog.filters')}
                            {count > 0 && (
                                <span className="rounded-full bg-foreground px-1.5 text-xs text-background">
                                    {count}
                                </span>
                            )}
                        </Button>
                    </SheetTrigger>
                    <SheetContent
                        side="left"
                        className="store-theme flex w-[88vw] max-w-sm flex-col bg-background text-foreground"
                    >
                        <SheetHeader className="border-b border-border/60">
                            <SheetTitle>{t('catalog.filters')}</SheetTitle>
                        </SheetHeader>
                        <div className="flex-1 overflow-y-auto px-4">
                            {panel}
                        </div>
                        <SheetFooter className="flex-row gap-2 border-t border-border/60">
                            {count > 0 && (
                                <Button
                                    variant="outline"
                                    className="flex-1"
                                    onClick={clear}
                                >
                                    {t('catalog.clear')}
                                </Button>
                            )}
                            <Button
                                className="flex-1"
                                onClick={() => setSheetOpen(false)}
                            >
                                {t('catalog.apply')} ({catalog.total})
                            </Button>
                        </SheetFooter>
                    </SheetContent>
                </Sheet>

                <Select
                    value={filters.ordem}
                    onValueChange={(ordem) => apply({ ordem })}
                >
                    <SelectTrigger
                        className="w-auto min-w-44"
                        aria-label={t('catalog.sort')}
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent className="store-theme">
                        {SORTS.map((sort) => (
                            <SelectItem key={sort} value={sort}>
                                {t(`catalog.sort_options.${sort}`)}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>

            <div
                className={cn(
                    'mt-6 grid gap-8',
                    side && 'lg:grid-cols-[15rem_1fr] lg:gap-10',
                )}
            >
                {side && (
                    // Uma secao e nao um <aside>: os filtros sao parte do
                    // conteudo principal, e um landmark complementar dentro
                    // do <main> confunde os leitores de ecra.
                    <section
                        aria-label={t('catalog.filters')}
                        className="hidden lg:block"
                    >
                        {panel}
                    </section>
                )}

                <div className="min-w-0">
                    <div className="mb-5 flex flex-wrap items-center gap-2">
                        <p
                            className="mr-2 text-sm text-muted-foreground"
                            aria-live="polite"
                        >
                            {t.choice('catalog.results', catalog.total)}
                        </p>
                        {chips.map((chip) => (
                            <button
                                key={chip.label}
                                type="button"
                                onClick={chip.remove}
                                className="flex items-center gap-1 rounded-full bg-muted px-3 py-1 text-xs transition-colors hover:bg-accent"
                                aria-label={t('catalog.remove_filter', {
                                    name: chip.label,
                                })}
                            >
                                {chip.label}
                                <X aria-hidden="true" className="size-3" />
                            </button>
                        ))}
                        {chips.length > 1 && (
                            <button
                                type="button"
                                onClick={clear}
                                className="text-xs text-muted-foreground underline underline-offset-4 hover:text-foreground"
                            >
                                {t('catalog.clear')}
                            </button>
                        )}
                    </div>

                    {products.data.length === 0 ? (
                        <div className="rounded-xl border border-dashed border-border px-6 py-16 text-center">
                            <p className="text-lg font-medium">
                                {data.empty_title}
                            </p>
                            {data.empty_text && (
                                <p className="mx-auto mt-2 max-w-md text-sm text-muted-foreground">
                                    {data.empty_text}
                                </p>
                            )}
                            <div className="mt-6 flex flex-wrap justify-center gap-3">
                                {chips.length > 0 && (
                                    <Button variant="outline" onClick={clear}>
                                        {t('catalog.clear')}
                                    </Button>
                                )}
                                <Button asChild>
                                    <Link href={localized('/personalizado')}>
                                        {t('nav.custom')}
                                    </Link>
                                </Button>
                            </div>
                        </div>
                    ) : (
                        <InfiniteScroll
                            data="products"
                            manual
                            className={cn(
                                'grid grid-cols-2 gap-x-4 gap-y-10 md:gap-x-6',
                                side
                                    ? 'md:grid-cols-3'
                                    : 'md:grid-cols-3 xl:grid-cols-4',
                            )}
                            next={({ fetch, hasMore, loading }) =>
                                hasMore ? (
                                    <div className="col-span-full mt-4 flex justify-center">
                                        <Button
                                            variant="outline"
                                            size="lg"
                                            onClick={fetch}
                                            disabled={loading}
                                        >
                                            {loading
                                                ? t('catalog.loading')
                                                : t('catalog.load_more')}
                                        </Button>
                                    </div>
                                ) : null
                            }
                        >
                            {products.data.map((product, index) => (
                                <ProductCard
                                    key={product.id}
                                    product={product}
                                    headingLevel={2}
                                    priority={index < 4}
                                    sizes={
                                        side
                                            ? '(min-width: 1024px) 22vw, (min-width: 768px) 33vw, 50vw'
                                            : '(min-width: 1280px) 25vw, (min-width: 768px) 33vw, 50vw'
                                    }
                                />
                            ))}
                        </InfiniteScroll>
                    )}
                </div>
            </div>
        </BlockSection>
    );
}
