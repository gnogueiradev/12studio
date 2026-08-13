import { Head, Link, router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { AdminTable } from '@/components/admin/admin-table';
import type { Column } from '@/components/admin/admin-table';
import { ColorCreateDialog } from '@/components/admin/color-create-dialog';
import { ColorSwatch } from '@/components/admin/color-swatch';
import { ConfirmDialog } from '@/components/admin/confirm-dialog';
import { FilterChip } from '@/components/admin/filter-chip';
import { PageHeader } from '@/components/admin/page-header';
import { StatCard } from '@/components/admin/stat-card';
import { StatusBadge } from '@/components/admin/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatCents } from '@/lib/money';
import { label } from '@/lib/options';
import { fold } from '@/lib/text';
import { cn } from '@/lib/utils';
import { destroy, index, restaurar } from '@/routes/admin/cores';
import { index as materiaisIndex } from '@/routes/admin/materiais';
import type {
    ColorGroupRow,
    ColorMaterialCard,
    ColorPriceOrigin,
    ColorStats,
    PaletteColor,
} from '@/types/catalog';
import { COLOR_STATES } from '@/types/catalog';

type Props = {
    colors: ColorGroupRow[];
    materials: ColorMaterialCard[];
    stats: ColorStats;
    palette: PaletteColor[];
};

type View = 'color' | 'material';

const ALL = 'all';

/**
 * Chips como predicados, não como partição — mesma regra dos materiais. Uma cor
 * com stock baixo continua disponível: só tem um estado mais urgente para
 * mostrar na pastilha, e quem clica em "Disponíveis" quer vê-la na mesma.
 */
const MATCHERS: Record<string, (color: ColorGroupRow) => boolean> = {
    [ALL]: () => true,
    active: (color) => color.state !== 'archived',
    low_stock: (color) => color.state === 'low_stock',
    archived: (color) => color.state === 'archived',
};

const PRICE_ORIGINS: Record<ColorPriceOrigin, string> = {
    none: '',
    inherited: 'herdado',
    own: 'preço próprio',
    // O caso que o desenho não previa: override nuns materiais, herdado
    // noutros. Dizê-lo é o que impede o intervalo de parecer um erro.
    mixed: 'misto',
};

export default function ColorsIndex({
    colors,
    materials,
    stats,
    palette,
}: Props) {
    /*
     * Filtros no cliente, como nos materiais: esta listagem não pagina e são
     * meia dúzia de linhas que a página já trouxe inteiras.
     */
    const [search, setSearch] = useState('');
    const [state, setState] = useState<string>(ALL);
    const [view, setView] = useState<View>('color');
    /*
     * `?novo=1` pede o modal já aberto. Lido do `usePage().url` e não do
     * `window.location` para o SSR não ir abaixo à procura de um `window`.
     */
    const { url } = usePage();
    const [creating, setCreating] = useState(() => url.includes('novo=1'));
    const [editing, setEditing] = useState<ColorGroupRow | null>(null);
    const [archiving, setArchiving] = useState<ColorGroupRow | null>(null);

    const needle = fold(search.trim());

    const visible = useMemo(
        () =>
            colors.filter((color) => {
                if (!MATCHERS[state](color)) {
                    return false;
                }

                return (
                    needle === '' ||
                    fold(color.name).includes(needle) ||
                    fold(color.hex).includes(needle) ||
                    color.materials.some((material) =>
                        fold(material.name).includes(needle),
                    )
                );
            }),
        [colors, needle, state],
    );

    /*
     * Contagens sobre a lista COMPLETA, nunca sobre a filtrada: senão todas as
     * chips excepto a ativa mostravam zero e deixavam de servir para navegar.
     */
    const counts = useMemo(
        () =>
            Object.fromEntries(
                Object.entries(MATCHERS).map(([key, matches]) => [
                    key,
                    colors.filter(matches).length,
                ]),
            ),
        [colors],
    );

    /* A vista por material responde à pesquisa pelo nome do material ou pelo de
     * uma cor lá dentro. As chips de estado não se lhe aplicam — são estados de
     * cor — e por isso deixam de aparecer, em vez de ficarem ali sem efeito. */
    const visibleMaterials = useMemo(
        () =>
            materials.filter(
                (material) =>
                    needle === '' ||
                    fold(material.name).includes(needle) ||
                    material.colors.some((color) =>
                        fold(color.name).includes(needle),
                    ),
            ),
        [materials, needle],
    );

    const price = (color: ColorGroupRow) => {
        if (color.minPricePerKgCents === null) {
            return <span className="text-muted-foreground">—</span>;
        }

        return (
            <>
                <span className="tabular-nums">
                    {color.minPricePerKgCents === color.maxPricePerKgCents
                        ? formatCents(color.minPricePerKgCents)
                        : `${formatCents(color.minPricePerKgCents)} – ${formatCents(color.maxPricePerKgCents ?? 0)}`}
                </span>
                <span
                    className={cn(
                        'mt-0.5 block text-xs',
                        color.priceOrigin === 'inherited'
                            ? 'text-muted-foreground'
                            : 'text-info',
                    )}
                >
                    {PRICE_ORIGINS[color.priceOrigin]}
                </span>
            </>
        );
    };

    const columns: Column<ColorGroupRow>[] = [
        {
            key: 'name',
            header: 'Cor',
            cell: (color) => (
                <span className="flex items-center gap-3">
                    <ColorSwatch
                        hex={color.hex}
                        className="size-6 rounded-lg"
                    />
                    <span>
                        <span className="block font-medium">{color.name}</span>
                        <span className="mt-0.5 block font-mono text-xs text-muted-foreground">
                            {color.hex}
                        </span>
                    </span>
                </span>
            ),
        },
        {
            key: 'materials',
            header: 'Materiais',
            cell: (color) =>
                color.materials.length === 0 ? (
                    <span className="text-xs text-muted-foreground">
                        Sem materiais
                    </span>
                ) : (
                    <span className="flex flex-wrap gap-1.5">
                        {color.materials.map((material) => (
                            <span
                                key={material.id}
                                className="rounded-md border border-border px-2 py-0.5 text-xs"
                            >
                                {material.name}
                            </span>
                        ))}
                    </span>
                ),
        },
        {
            key: 'price',
            header: 'Preço por kg',
            className: 'text-right',
            cell: price,
        },
        {
            key: 'variants',
            header: 'Variantes',
            className: 'text-right tabular-nums',
            cell: (color) => color.variantsCount,
        },
        {
            key: 'state',
            header: 'Estado',
            cell: (color) => (
                <StatusBadge
                    value={color.state}
                    label={label(COLOR_STATES, color.state)}
                />
            ),
        },
        {
            key: 'actions',
            header: '',
            className: 'text-right',
            cell: (color) => (
                <div className="flex justify-end gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => setEditing(color)}
                    >
                        Editar
                    </Button>
                    {color.state === 'archived' ? (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() =>
                                router.patch(
                                    restaurar(color.id).url,
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
                    description="Cada cor existe num ou mais materiais e herda o preço por quilo de cada um, a não ser que lhe dês um próprio."
                >
                    <Button variant="outline" asChild>
                        <Link href={materiaisIndex()}>Materiais</Link>
                    </Button>
                    <Button onClick={() => setCreating(true)}>Nova cor</Button>
                </PageHeader>

                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <StatCard
                        label="Cores ativas"
                        value={String(stats.activeCount)}
                    />
                    <StatCard
                        label="Combinações cor × material"
                        value={String(stats.pairsCount)}
                    />
                    <StatCard
                        label="Com preço próprio"
                        value={String(stats.ownPriceCount)}
                    />
                    <StatCard
                        label="Sem variantes"
                        value={String(stats.unusedCount)}
                        tone={stats.unusedCount > 0 ? 'warning' : 'default'}
                    />
                </div>

                <div className="flex flex-wrap items-center gap-3">
                    <Input
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        placeholder="Nome, hex ou material"
                        className="max-w-xs"
                        aria-label="Pesquisar cores"
                    />
                    {view === 'color' && (
                        <div className="flex flex-wrap gap-2">
                            <FilterChip
                                text="Todas"
                                count={counts[ALL]}
                                active={state === ALL}
                                onClick={() => setState(ALL)}
                            />
                            {COLOR_STATES.map((option) => (
                                <FilterChip
                                    key={option.value}
                                    text={option.chipLabel}
                                    count={counts[option.value]}
                                    active={state === option.value}
                                    onClick={() => setState(option.value)}
                                />
                            ))}
                        </div>
                    )}

                    <div
                        role="group"
                        aria-label="Agrupar por"
                        className="ml-auto flex gap-1 rounded-full border border-border p-1"
                    >
                        {(
                            [
                                ['color', 'Por cor'],
                                ['material', 'Por material'],
                            ] as const
                        ).map(([value, text]) => (
                            <button
                                key={value}
                                type="button"
                                onClick={() => setView(value)}
                                aria-pressed={view === value}
                                className={cn(
                                    'rounded-full px-3.5 py-1 text-xs transition-colors',
                                    view === value
                                        ? 'bg-secondary font-semibold text-foreground'
                                        : 'font-medium text-muted-foreground hover:bg-secondary/60',
                                )}
                            >
                                {text}
                            </button>
                        ))}
                    </div>

                    <span className="text-xs text-muted-foreground">
                        {view === 'color'
                            ? `${visible.length} ${visible.length === 1 ? 'cor' : 'cores'}`
                            : `${visibleMaterials.length} ${visibleMaterials.length === 1 ? 'material' : 'materiais'}`}
                    </span>
                </div>

                {view === 'color' ? (
                    <AdminTable
                        columns={columns}
                        rows={visible}
                        rowKey={(color) => color.id}
                        rowClassName={(color) =>
                            color.state === 'archived' ? 'opacity-60' : ''
                        }
                        empty={
                            colors.length === 0
                                ? 'Ainda não há cores. Cria um material primeiro e depois as cores em que o imprimes.'
                                : 'Nenhuma cor com estes filtros.'
                        }
                    />
                ) : visibleMaterials.length === 0 ? (
                    <p className="rounded-xl border border-dashed border-border p-12 text-center text-sm text-muted-foreground">
                        Nenhum material com estes filtros.
                    </p>
                ) : (
                    <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        {visibleMaterials.map((material) => (
                            <div
                                key={material.id}
                                className={cn(
                                    'rounded-xl border border-border/60 bg-card p-4',
                                    !material.active && 'opacity-60',
                                )}
                            >
                                <div className="flex items-baseline justify-between gap-3 border-b border-border/60 pb-3">
                                    <span className="font-medium">
                                        {material.name}
                                    </span>
                                    <span className="text-xs text-muted-foreground tabular-nums">
                                        {formatCents(material.pricePerKgCents)}{' '}
                                        / kg
                                    </span>
                                </div>
                                {material.colors.length === 0 ? (
                                    <p className="pt-3 text-xs text-muted-foreground">
                                        Sem cores — este material não gera
                                        variante nenhuma.
                                    </p>
                                ) : (
                                    <div className="flex flex-wrap gap-2 pt-3">
                                        {material.colors.map((color) => (
                                            <span
                                                key={color.name}
                                                className="flex items-center gap-2 rounded-full border border-border py-1 pr-3 pl-2 text-xs"
                                            >
                                                <ColorSwatch
                                                    hex={color.hex}
                                                    className="size-3.5"
                                                />
                                                {color.name}
                                                {color.ownPricePerKgCents !==
                                                    null && (
                                                    <span className="text-info tabular-nums">
                                                        {formatCents(
                                                            color.ownPricePerKgCents,
                                                        )}
                                                    </span>
                                                )}
                                            </span>
                                        ))}
                                    </div>
                                )}
                            </div>
                        ))}
                    </div>
                )}
            </div>

            {/*
             * A `key` é o que sincroniza o formulário com o alvo: remontar o
             * modal quando se passa de "nova" para "editar a Terracota" é mais
             * previsível do que um useEffect a chamar setData por cima do que o
             * admin está a escrever.
             */}
            <ColorCreateDialog
                key={editing?.id ?? 'new'}
                open={creating || editing !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setCreating(false);
                        setEditing(null);
                    }
                }}
                editing={editing}
                materials={materials}
                palette={palette}
            />

            <ConfirmDialog
                open={archiving !== null}
                onOpenChange={(open) => !open && setArchiving(null)}
                title="Arquivar cor"
                description={
                    <>
                        A cor <strong>{archiving?.name}</strong> deixa de
                        aparecer ao criar variantes, nos{' '}
                        {archiving?.materials.length ?? 0} materiais onde
                        existe. As {archiving?.variantsCount ?? 0} variantes que
                        já a usam mantêm-se.
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
