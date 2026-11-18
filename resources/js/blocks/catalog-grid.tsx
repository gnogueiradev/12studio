import { InfiniteScroll, Link, router, usePage } from '@inertiajs/react';
import { Check, ChevronDown, Search, SlidersHorizontal, X } from 'lucide-react';
import { useId, useState } from 'react';
import type { ReactNode } from 'react';
import { ProductCard } from '@/components/store/product-card';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
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
import { familySwatch, groupByFamily } from '@/lib/color-families';
import type { FamilyGroup } from '@/lib/color-families';
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
type ColorFamilies = FamilyGroup<CatalogData['facets']['colors'][number]>[];

const SORTS = ['destaques', 'novidades', 'preco-asc', 'preco-desc'] as const;

/**
 * O `reset` faz da visita um pedido parcial: o Inertia só pede as props que lá
 * estão. Sem o `only`, o `catalog` (filtros ativos, contagem) nunca voltava do
 * servidor — a grelha filtrava, mas a contagem, as etiquetas e o filtro
 * seguinte continuavam a partir do estado antigo.
 */
const VISIT = { only: ['catalog'], reset: ['products'] };

/** As categorias por cima da grelha: a forma principal de andar no catálogo. */
const CATEGORY_PILL =
    'inline-flex h-9 items-center rounded-full border border-border px-4 text-sm whitespace-nowrap outline-none transition-colors hover:border-foreground/40 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background aria-[current=page]:border-foreground aria-[current=page]:bg-foreground aria-[current=page]:text-background';

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

function FilterSection({
    title,
    children,
}: {
    title: string;
    children: ReactNode;
}) {
    return (
        <fieldset className="border-b border-border/60 pb-5 last:border-b-0 last:pb-0">
            <legend className="mb-3 text-sm font-semibold">{title}</legend>
            {children}
        </fieldset>
    );
}

/**
 * Um filtro que só abre a pedido (material, preço): estão lá para quem os
 * procura, sem pesarem na coluna. Com o filtro já aplicado nasce aberto, para
 * não esconder o que está a mexer na lista.
 */
function CollapsibleFilter({
    title,
    defaultOpen,
    children,
}: {
    title: string;
    defaultOpen: boolean;
    children: ReactNode;
}) {
    return (
        <Collapsible
            defaultOpen={defaultOpen}
            className="border-b border-border/60 pb-5 last:border-b-0 last:pb-0"
        >
            <CollapsibleTrigger className="group/trigger flex min-h-6 w-full items-center justify-between gap-3 rounded-md text-left text-sm font-semibold outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background">
                {title}
                <ChevronDown
                    aria-hidden="true"
                    className="size-4 text-muted-foreground transition-transform group-data-[state=open]/trigger:rotate-180"
                />
            </CollapsibleTrigger>
            <CollapsibleContent className="pt-3">{children}</CollapsibleContent>
        </Collapsible>
    );
}

/**
 * Os filtros, iguais na coluna (desktop) e no painel (telemóvel). Cada
 * mudança é uma visita com o URL novo: os filtros partilham-se por link e o
 * "voltar" do browser funciona.
 */
function FilterPanel({
    catalog,
    families,
    apply,
}: {
    catalog: CatalogData;
    families: ColorFamilies;
    apply: (next: Partial<Filters>) => void;
}) {
    const t = useT();
    const { filters, facets } = catalog;
    const [price, setPrice] = useState({
        min: filters.preco_min?.toString() ?? '',
        max: filters.preco_max?.toString() ?? '',
    });

    // Os campos seguem o filtro quando ele muda por fora (tirar a etiqueta,
    // limpar tudo): sem isto ficavam com o valor antigo, e o blur seguinte
    // voltava a aplicá-lo. Cada campo por si — acertar o mínimo não pode
    // apagar o máximo que se está a escrever.
    const [applied, setApplied] = useState({
        min: filters.preco_min,
        max: filters.preco_max,
    });

    if (
        applied.min !== filters.preco_min ||
        applied.max !== filters.preco_max
    ) {
        setApplied({ min: filters.preco_min, max: filters.preco_max });
        setPrice({
            min:
                applied.min !== filters.preco_min
                    ? (filters.preco_min?.toString() ?? '')
                    : price.min,
            max:
                applied.max !== filters.preco_max
                    ? (filters.preco_max?.toString() ?? '')
                    : price.max,
        });
    }

    // O painel existe duas vezes na página (coluna e painel do telemóvel):
    // o id do rótulo do interruptor não pode ser fixo.
    const readyLabel = useId();

    const toggleMaterial = (id: number) =>
        apply({
            material: filters.material.includes(id)
                ? filters.material.filter((value) => value !== id)
                : [...filters.material, id],
        });

    const applyPrice = (event?: React.FormEvent) => {
        event?.preventDefault();
        const parse = (value: string) =>
            value.trim() === '' || Number.isNaN(Number(value))
                ? null
                : Math.max(0, Math.round(Number(value)));

        const next = {
            preco_min: parse(price.min),
            preco_max: parse(price.max),
        };

        // O blur chama isto sempre: sem mudança não há visita nenhuma.
        if (
            next.preco_min !== filters.preco_min ||
            next.preco_max !== filters.preco_max
        ) {
            apply(next);
        }
    };

    return (
        <div className="flex flex-col gap-5">
            {families.length > 0 && (
                <FilterSection title={t('catalog.colors')}>
                    <ul className="grid grid-cols-2 gap-x-2">
                        {families.map(({ family, colors }) => {
                            const ids = colors.map((color) => color.id);
                            const active = ids.every((id) =>
                                filters.cor.includes(id),
                            );

                            return (
                                <li key={family} className="min-w-0">
                                    <button
                                        type="button"
                                        onClick={() =>
                                            apply({
                                                cor: active
                                                    ? filters.cor.filter(
                                                          (id) =>
                                                              !ids.includes(id),
                                                      )
                                                    : [
                                                          ...new Set([
                                                              ...filters.cor,
                                                              ...ids,
                                                          ]),
                                                      ],
                                            })
                                        }
                                        // "mixed": só alguns tons da família
                                        // estão escolhidos (um link antigo).
                                        aria-pressed={
                                            active ||
                                            (ids.some((id) =>
                                                filters.cor.includes(id),
                                            )
                                                ? 'mixed'
                                                : false)
                                        }
                                        // Os nomes dos tons que a família
                                        // junta, para quem quiser saber.
                                        title={colors
                                            .map((color) => color.name)
                                            .join(', ')}
                                        className="flex w-full items-center gap-2 rounded-md py-1.5 text-left text-sm text-muted-foreground transition-colors hover:text-foreground aria-pressed:font-medium aria-pressed:text-foreground"
                                    >
                                        {/* A borda vem da cor do texto e não
                                            da `border`: um preto sobre o tema
                                            escuro ficava sem contorno. */}
                                        <span
                                            aria-hidden="true"
                                            className={cn(
                                                'flex size-5 shrink-0 items-center justify-center rounded-full border border-foreground/25 ring-offset-2 ring-offset-background transition-shadow',
                                                active &&
                                                    'ring-2 ring-foreground',
                                            )}
                                            style={{
                                                background: familySwatch(
                                                    colors.map(
                                                        (color) => color.hex,
                                                    ),
                                                ),
                                            }}
                                        >
                                            {active && (
                                                <Check className="size-3 text-white mix-blend-difference" />
                                            )}
                                        </span>
                                        <span className="truncate">
                                            {t(
                                                `catalog.color_families.${family}`,
                                            )}
                                        </span>
                                    </button>
                                </li>
                            );
                        })}
                    </ul>
                </FilterSection>
            )}

            {/* Um interruptor e não três botões de rádio: a pergunta de quem
                compra é só "há para já?". */}
            <div className="border-b border-border/60 pb-5 last:border-b-0 last:pb-0">
                <label className="flex cursor-pointer items-center justify-between gap-3 text-sm font-semibold">
                    <span id={readyLabel}>{t('catalog.ready_only')}</span>
                    <button
                        type="button"
                        role="switch"
                        aria-labelledby={readyLabel}
                        aria-checked={filters.disponibilidade === 'pronto'}
                        onClick={() =>
                            apply({
                                disponibilidade:
                                    filters.disponibilidade === 'pronto'
                                        ? null
                                        : 'pronto',
                            })
                        }
                        className="group/switch inline-flex h-6 w-10 shrink-0 items-center rounded-full border border-muted-foreground px-0.5 transition-colors outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background aria-checked:border-foreground aria-checked:bg-foreground"
                    >
                        <span
                            aria-hidden="true"
                            className="size-4 rounded-full bg-muted-foreground transition-transform group-aria-checked/switch:translate-x-[18px] group-aria-checked/switch:bg-background"
                        />
                    </button>
                </label>
            </div>

            {facets.materials.length > 1 && (
                <CollapsibleFilter
                    title={t('catalog.materials')}
                    defaultOpen={filters.material.length > 0}
                >
                    <ul className="flex flex-wrap gap-2">
                        {facets.materials.map((material) => (
                            <li key={material.id}>
                                <button
                                    type="button"
                                    onClick={() => toggleMaterial(material.id)}
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
                </CollapsibleFilter>
            )}

            {facets.price.max > facets.price.min && (
                <CollapsibleFilter
                    title={t('catalog.price')}
                    defaultOpen={
                        filters.preco_min !== null || filters.preco_max !== null
                    }
                >
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
                </CollapsibleFilter>
            )}
        </div>
    );
}

type Data = { empty_title: string; empty_text: string };

export function CatalogGridBlock({
    block,
    nested,
}: {
    block: StoreBlock;
    nested: boolean;
}) {
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
    const side = block.variant !== 'filters_top';

    // Uma família escolhida por inteiro é um filtro só, na etiqueta e na
    // contagem; uma cor solta (um link antigo, com um só tom) fica com o nome.
    const families = groupByFamily(catalog.facets.colors);
    const chosenFamilies = families.filter(({ colors }) =>
        colors.every((color) => filters.cor.includes(color.id)),
    );
    // Só as que ainda existem no catálogo: um id de um link antigo, de uma cor
    // que já não tem peças à venda, dava uma etiqueta sem nome.
    const looseColors = catalog.facets.colors.filter(
        (color) =>
            filters.cor.includes(color.id) &&
            !chosenFamilies.some(({ colors }) => colors.includes(color)),
    );

    const apply = (next: Partial<Filters>) =>
        router.get(catalog.path, toQuery({ ...filters, ...next }), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            ...VISIT,
        });

    const clear = () => {
        setQuery('');
        router.get(
            catalog.path,
            filters.ordem !== 'destaques' ? { ordem: filters.ordem } : {},
            { preserveScroll: true, ...VISIT },
        );
    };

    // A `key` é o tipo e o id, não o texto: uma cor solta pode chamar-se como
    // uma família ("Laranja") e as duas etiquetas chocavam.
    const chips: { key: string; label: string; remove: () => void }[] = [
        ...(filters.q
            ? [
                  {
                      key: 'q',
                      label: t('catalog.search_for', { q: filters.q }),
                      remove: () => {
                          setQuery('');
                          apply({ q: '' });
                      },
                  },
              ]
            : []),
        ...chosenFamilies.map(({ family, colors }) => ({
            key: `family:${family}`,
            label: t(`catalog.color_families.${family}`),
            remove: () =>
                apply({
                    cor: filters.cor.filter(
                        (id) => !colors.some((color) => color.id === id),
                    ),
                }),
        })),
        ...looseColors.map(({ id, name }) => ({
            key: `color:${id}`,
            label: name,
            remove: () =>
                apply({ cor: filters.cor.filter((value) => value !== id) }),
        })),
        ...catalog.facets.materials
            .filter(({ id }) => filters.material.includes(id))
            .map(({ id, name }) => ({
                key: `material:${id}`,
                label: name,
                remove: () =>
                    apply({
                        material: filters.material.filter(
                            (value) => value !== id,
                        ),
                    }),
            })),
        ...(filters.preco_min !== null || filters.preco_max !== null
            ? [
                  {
                      key: 'price',
                      label: `${filters.preco_min ?? catalog.facets.price.min}–${filters.preco_max ?? catalog.facets.price.max} €`,
                      remove: () => apply({ preco_min: null, preco_max: null }),
                  },
              ]
            : []),
        ...(filters.disponibilidade
            ? [
                  {
                      key: 'availability',
                      label:
                          filters.disponibilidade === 'pronto'
                              ? t('catalog.ready')
                              : t('catalog.to_order'),
                      remove: () => apply({ disponibilidade: null }),
                  },
              ]
            : []),
    ];

    // No botão "Filtros": os filtros ativos, sem contar a pesquisa.
    const count = chips.length - (filters.q ? 1 : 0);

    const panel = (
        <FilterPanel catalog={catalog} families={families} apply={apply} />
    );

    return (
        <BlockSection shell={!nested} style={block.style}>
            {catalog.facets.categories.length > 1 && (
                // A margem negativa com o padding igual dá espaço ao anel de
                // foco, que o overflow do scroll horizontal cortava.
                <nav
                    aria-label={t('catalog.categories')}
                    className="-m-1 mb-4 [scrollbar-width:none] overflow-x-auto p-1"
                >
                    {/* No telemóvel rola para o lado com o dedo; com rato não
                        há como, por isso em ecrãs largos passa para várias
                        linhas. */}
                    <ul className="flex w-max gap-2 @blk-md:w-auto @blk-md:flex-wrap">
                        <li>
                            <Link
                                href={localized('/produtos')}
                                aria-current={
                                    catalog.category === null
                                        ? 'page'
                                        : undefined
                                }
                                className={CATEGORY_PILL}
                            >
                                {t('catalog.all')}
                            </Link>
                        </li>
                        {catalog.facets.categories.map((category) => (
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
                                    className={CATEGORY_PILL}
                                >
                                    {category.name}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </nav>
            )}

            <div className="flex flex-wrap items-center gap-3 border-b border-border/60 pb-4">
                {/* No telemóvel: a pesquisa numa linha, filtros e ordenação a
                    meias na seguinte. A partir do tablet, tudo numa linha só,
                    à direita da contagem. */}
                <div className="grid w-full grid-cols-2 gap-2 @blk-md:ml-auto @blk-md:flex @blk-md:w-auto @blk-md:items-center">
                    <form
                        role="search"
                        className="relative col-span-2 min-w-0 @blk-md:w-60"
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
                                className={cn(
                                    'gap-2',
                                    side && '@blk-lg:hidden',
                                )}
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
                            className="w-full @blk-md:w-auto @blk-md:min-w-44"
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

                <p
                    className="text-sm font-medium @blk-md:order-first"
                    aria-live="polite"
                >
                    {t.choice('catalog.results', catalog.total)}
                </p>
            </div>

            <div
                className={cn(
                    'mt-6 grid gap-8',
                    // 13rem: duas colunas de cores onde "Castanho" e
                    // "Vermelho" ainda cabem inteiros.
                    side && '@blk-lg:grid-cols-[13rem_1fr]',
                )}
            >
                {side && (
                    // Uma secao e nao um <aside>: os filtros sao parte do
                    // conteudo principal, e um landmark complementar dentro
                    // do <main> confunde os leitores de ecra.
                    //
                    // Acompanha o scroll, por baixo do cabeçalho fixo; se for
                    // mais alta do que o ecrã, rola por dentro em vez de
                    // deixar os últimos filtros fora de alcance.
                    <section
                        aria-label={t('catalog.filters')}
                        // A margem negativa com o padding igual: o overflow
                        // cortava os anéis de foco encostados às bordas.
                        className="hidden @blk-lg:sticky @blk-lg:top-24 @blk-lg:-m-1 @blk-lg:block @blk-lg:max-h-[calc(100dvh-7rem)] @blk-lg:self-start @blk-lg:overflow-y-auto @blk-lg:p-1"
                    >
                        {panel}
                    </section>
                )}

                <div className="min-w-0">
                    {chips.length > 0 && (
                        <div className="mb-5 flex flex-wrap items-center gap-2">
                            {chips.map((chip) => (
                                <button
                                    key={chip.key}
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
                    )}

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
                            // Com a coluna dos filtros ao lado, a quarta
                            // coluna só entra quando a página tem a largura
                            // toda; sem ela, entra mais cedo.
                            className={cn(
                                'grid grid-cols-2 gap-x-4 gap-y-10 @blk-md:grid-cols-3 @blk-md:gap-x-5',
                                side
                                    ? '@min-[68rem]:grid-cols-4'
                                    : '@blk-lg:grid-cols-4',
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
                                    showCategory={catalog.category === null}
                                    priority={index < 4}
                                    sizes={
                                        side
                                            ? '(min-width: 1184px) 210px, (min-width: 1024px) 22vw, (min-width: 768px) 33vw, 50vw'
                                            : '(min-width: 1184px) 270px, (min-width: 1024px) 25vw, (min-width: 768px) 33vw, 50vw'
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
