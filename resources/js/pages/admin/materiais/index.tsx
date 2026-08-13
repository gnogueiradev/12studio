import { Head, Link, router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { AdminTable } from '@/components/admin/admin-table';
import type { Column } from '@/components/admin/admin-table';
import { ColorSwatch } from '@/components/admin/color-swatch';
import { ConfirmDialog } from '@/components/admin/confirm-dialog';
import { FilterChip } from '@/components/admin/filter-chip';
import { MaterialCreateDialog } from '@/components/admin/material-create-dialog';
import { PageHeader } from '@/components/admin/page-header';
import { StatCard } from '@/components/admin/stat-card';
import { StatusBadge } from '@/components/admin/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatCents, formatCostPerGram } from '@/lib/money';
import { label } from '@/lib/options';
import { fold } from '@/lib/text';
import { cn } from '@/lib/utils';
import { index as coresIndex } from '@/routes/admin/cores';
import { destroy, index, restaurar } from '@/routes/admin/materiais';
import type { MaterialRow, MaterialStats, PaletteColor } from '@/types/catalog';
import { MATERIAL_STATES } from '@/types/catalog';

type Props = {
    materials: MaterialRow[];
    stats: MaterialStats;
    /** As cores que já existem em /admin/cores — as chips do modal de criação. */
    colorOptions: PaletteColor[];
    families: string[];
};

const ALL = 'all';

/** Swatches mostrados por linha antes do "+N". */
const SWATCH_LIMIT = 5;

/**
 * Chips como predicados, não como partição.
 *
 * Um material com pouco stock continua ativo — só tem um estado mais urgente
 * para mostrar na pastilha. Por isso "Ativos" conta-o na mesma e "Stock baixo"
 * é um subconjunto dele: quem clica em "Ativos" quer ver o que está em uso, não
 * o que está em uso e por acaso tem bobines a mais.
 */
const MATCHERS: Record<string, (material: MaterialRow) => boolean> = {
    [ALL]: () => true,
    active: (material) => material.state !== 'archived',
    low_stock: (material) => material.state === 'low_stock',
    archived: (material) => material.state === 'archived',
};

export default function MaterialsIndex({
    materials,
    stats,
    colorOptions,
    families,
}: Props) {
    /*
     * Filtros no cliente, ao contrário dos produtos: esta listagem não pagina e
     * são meia dúzia de linhas que a página já trouxe inteiras. Um pedido ao
     * servidor por tecla carregada não descobria nada que o browser já não
     * tivesse em mãos.
     */
    const [search, setSearch] = useState('');
    const [state, setState] = useState<string>(ALL);
    /*
     * `?novo=1` pede o modal já aberto (o mesmo atalho que os produtos usam a
     * partir do painel). Lido do `usePage().url` e não do `window.location`
     * para o SSR não ir abaixo à procura de um `window` que lá não existe.
     */
    const { url } = usePage();
    const [creating, setCreating] = useState(() => url.includes('novo=1'));
    const [editing, setEditing] = useState<MaterialRow | null>(null);
    const [archiving, setArchiving] = useState<MaterialRow | null>(null);

    const visible = useMemo(() => {
        const needle = fold(search.trim());

        return materials.filter((material) => {
            if (!MATCHERS[state](material)) {
                return false;
            }

            return (
                needle === '' ||
                fold(material.name).includes(needle) ||
                fold(material.supplier ?? '').includes(needle)
            );
        });
    }, [materials, search, state]);

    /*
     * Contagens sobre a lista COMPLETA, nunca sobre a filtrada: senão todas as
     * chips excepto a ativa mostravam zero e deixavam de servir para navegar.
     * É a mesma regra que o servidor aplica nas listagens que paginam.
     */
    const counts = useMemo(
        () =>
            Object.fromEntries(
                Object.entries(MATCHERS).map(([key, matches]) => [
                    key,
                    materials.filter(matches).length,
                ]),
            ),
        [materials],
    );

    const columns: Column<MaterialRow>[] = [
        {
            key: 'name',
            header: 'Material',
            cell: (material) => (
                <div>
                    <span className="font-medium">{material.name}</span>
                    <span className="mt-0.5 block text-xs text-muted-foreground">
                        {[material.family, material.supplier]
                            .filter(Boolean)
                            .join(' · ') || '—'}
                    </span>
                </div>
            ),
        },
        {
            key: 'colors',
            header: 'Cores',
            cell: (material) =>
                material.colors.length === 0 ? (
                    <span className="text-xs text-muted-foreground">
                        Sem cores
                    </span>
                ) : (
                    <span className="flex items-center gap-1.5">
                        {material.colors.slice(0, SWATCH_LIMIT).map((color) => (
                            <ColorSwatch
                                key={color.name}
                                hex={color.hex}
                                className="size-3.5"
                            />
                        ))}
                        {material.colors.length > SWATCH_LIMIT && (
                            <span className="text-xs text-muted-foreground">
                                +{material.colors.length - SWATCH_LIMIT}
                            </span>
                        )}
                    </span>
                ),
        },
        {
            key: 'price',
            header: 'Preço / kg',
            className: 'text-right tabular-nums',
            cell: (material) => formatCents(material.pricePerKgCents),
        },
        {
            key: 'cost',
            header: 'Custo / g',
            className: 'text-right tabular-nums text-muted-foreground',
            cell: (material) => formatCostPerGram(material.pricePerKgCents),
        },
        {
            key: 'stock',
            header: 'Stock',
            cell: (material) =>
                material.state === 'archived' ? (
                    <span className="text-muted-foreground">—</span>
                ) : (
                    <div>
                        <span
                            className={cn(
                                'tabular-nums',
                                material.state === 'low_stock' &&
                                    'text-warning',
                            )}
                        >
                            {material.spoolsInStock}{' '}
                            {material.spoolsInStock === 1
                                ? 'bobine'
                                : 'bobines'}
                        </span>
                        <span className="mt-0.5 block text-xs text-muted-foreground tabular-nums">
                            {material.minSpools > 0
                                ? `mín. ${material.minSpools}`
                                : 'sem mínimo'}
                        </span>
                    </div>
                ),
        },
        {
            key: 'state',
            header: 'Estado',
            cell: (material) => (
                <StatusBadge
                    value={material.state}
                    label={label(MATERIAL_STATES, material.state)}
                />
            ),
        },
        {
            key: 'actions',
            header: '',
            className: 'text-right',
            cell: (material) => (
                <div className="flex justify-end gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => setEditing(material)}
                    >
                        Editar
                    </Button>
                    {material.active ? (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => setArchiving(material)}
                        >
                            Arquivar
                        </Button>
                    ) : (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() =>
                                router.patch(
                                    restaurar(material.id).url,
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Restaurar
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
                    <Button onClick={() => setCreating(true)}>
                        Novo material
                    </Button>
                </PageHeader>

                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <StatCard
                        label="Materiais ativos"
                        value={String(stats.activeCount)}
                    />
                    <StatCard
                        label="Bobines em stock"
                        value={String(stats.spoolsTotal)}
                    />
                    <StatCard
                        label="Custo médio por quilo"
                        value={formatCents(stats.averagePricePerKgCents)}
                    />
                    <StatCard
                        label="Abaixo do mínimo"
                        value={String(stats.belowMinimumCount)}
                        tone={
                            stats.belowMinimumCount > 0 ? 'warning' : 'default'
                        }
                    />
                </div>

                <div className="flex flex-wrap items-center gap-3">
                    <Input
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        placeholder="Nome ou fornecedor"
                        className="max-w-xs"
                        aria-label="Pesquisar materiais"
                    />
                    <div className="flex flex-wrap gap-2">
                        <FilterChip
                            text="Todos"
                            count={counts[ALL]}
                            active={state === ALL}
                            onClick={() => setState(ALL)}
                        />
                        {MATERIAL_STATES.map((option) => (
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
                        {visible.length === 1 ? 'material' : 'materiais'}
                    </span>
                </div>

                <AdminTable
                    columns={columns}
                    rows={visible}
                    rowKey={(material) => material.id}
                    rowClassName={(material) =>
                        material.active ? '' : 'opacity-60'
                    }
                    empty={
                        materials.length === 0
                            ? 'Ainda não há materiais — cria o primeiro (PLA, PETG…) para lhe poderes juntar cores.'
                            : 'Nenhum material com estes filtros.'
                    }
                />
            </div>

            {/*
             * A `key` é o que faz o formulário nascer com o material certo: o
             * modal semeia-se do `editing` no primeiro render e não tem efeito
             * nenhum a sincronizá-lo depois. Sem ela, editar um material a
             * seguir a outro abria com o que lá tinha ficado.
             */}
            <MaterialCreateDialog
                key={editing?.id ?? 'new'}
                open={creating || editing !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setCreating(false);
                        setEditing(null);
                    }
                }}
                editing={editing}
                colorOptions={colorOptions}
                families={families}
            />

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
