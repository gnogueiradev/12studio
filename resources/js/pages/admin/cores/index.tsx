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
import { label } from '@/lib/options';
import { fold } from '@/lib/text';
import { destroy, index, restaurar } from '@/routes/admin/cores';
import { index as materiaisIndex } from '@/routes/admin/materiais';
import type { ColorRow, ColorStats, PaletteColor } from '@/types/catalog';
import { COLOR_STATES } from '@/types/catalog';

type Props = {
    colors: ColorRow[];
    stats: ColorStats;
    palette: PaletteColor[];
};

const ALL = 'all';

/** Chips como predicados, não como partição — mesma regra dos materiais. */
const MATCHERS: Record<string, (color: ColorRow) => boolean> = {
    [ALL]: () => true,
    active: (color) => color.state !== 'archived',
    archived: (color) => color.state === 'archived',
};

export default function ColorsIndex({ colors, stats, palette }: Props) {
    /*
     * Filtros no cliente, como nos materiais: esta listagem não pagina e são
     * meia dúzia de linhas que a página já trouxe inteiras.
     */
    const [search, setSearch] = useState('');
    const [state, setState] = useState<string>(ALL);
    /*
     * `?novo=1` pede o modal já aberto. Lido do `usePage().url` e não do
     * `window.location` para o SSR não ir abaixo à procura de um `window`.
     */
    const { url } = usePage();
    const [creating, setCreating] = useState(() => url.includes('novo=1'));
    const [editing, setEditing] = useState<ColorRow | null>(null);
    const [archiving, setArchiving] = useState<ColorRow | null>(null);

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
                    fold(color.hex).includes(needle)
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

    const columns: Column<ColorRow>[] = [
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
            key: 'sortOrder',
            header: 'Ordem',
            className: 'text-right tabular-nums',
            cell: (color) => color.sortOrder,
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
                    description="Uma cor é um nome e um tom, e imprime-se em qualquer material. O preço por quilo é da bobine — está em Materiais."
                >
                    <Button variant="outline" asChild>
                        <Link href={materiaisIndex()}>Materiais</Link>
                    </Button>
                    <Button onClick={() => setCreating(true)}>Nova cor</Button>
                </PageHeader>

                <div className="grid gap-3 sm:grid-cols-3">
                    <StatCard
                        label="Cores ativas"
                        value={String(stats.activeCount)}
                    />
                    <StatCard
                        label="Sem variantes"
                        value={String(stats.unusedCount)}
                        tone={stats.unusedCount > 0 ? 'warning' : 'default'}
                    />
                    <StatCard
                        label="Arquivadas"
                        value={String(stats.archivedCount)}
                    />
                </div>

                <div className="flex flex-wrap items-center gap-3">
                    <Input
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        placeholder="Nome ou hex"
                        className="max-w-xs"
                        aria-label="Pesquisar cores"
                    />
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

                    <span className="ml-auto text-xs text-muted-foreground">
                        {visible.length}{' '}
                        {visible.length === 1 ? 'cor' : 'cores'}
                    </span>
                </div>

                <AdminTable
                    columns={columns}
                    rows={visible}
                    rowKey={(color) => color.id}
                    rowClassName={(color) =>
                        color.state === 'archived' ? 'opacity-60' : ''
                    }
                    empty={
                        colors.length === 0
                            ? 'Ainda não há cores. Cria a primeira e depois cruza-a com um material ao criar um produto.'
                            : 'Nenhuma cor com estes filtros.'
                    }
                />
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
                palette={palette}
            />

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
