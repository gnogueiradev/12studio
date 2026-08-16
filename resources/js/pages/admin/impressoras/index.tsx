import { Head, Link, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { AdminTable } from '@/components/admin/admin-table';
import type { Column } from '@/components/admin/admin-table';
import { ConfirmDialog } from '@/components/admin/confirm-dialog';
import { FilterChip } from '@/components/admin/filter-chip';
import { PageHeader } from '@/components/admin/page-header';
import { PrinterCreateDialog } from '@/components/admin/printer-create-dialog';
import { StatCard } from '@/components/admin/stat-card';
import { StatusBadge } from '@/components/admin/status-badge';
import { Button } from '@/components/ui/button';
import { formatCents, formatMicros } from '@/lib/money';
import { label } from '@/lib/options';
import { calculadora } from '@/routes/admin';
import {
    destroy,
    index,
    predefinida,
    restaurar,
} from '@/routes/admin/impressoras';
import type { PrinterProfileRow, PrinterProfileStats } from '@/types/pricing';
import { PRINTER_STATES } from '@/types/pricing';

type Props = {
    printers: PrinterProfileRow[];
    stats: PrinterProfileStats;
    /** A tarifa global, só para o modal pré-visualizar o €/h enquanto se escreve. */
    electricityPriceMicrosPerKwh: number;
};

const ALL = 'all';

/** Uma peça de 1h30 — a leitura que torna o custo/hora concreto. */
const EXAMPLE_MINUTES = 90;

const MATCHERS: Record<string, (printer: PrinterProfileRow) => boolean> = {
    [ALL]: () => true,
    active: (printer) => printer.active,
    archived: (printer) => !printer.active,
};

export default function PrintersIndex({
    printers,
    stats,
    electricityPriceMicrosPerKwh,
}: Props) {
    const [state, setState] = useState<string>(ALL);
    const [creating, setCreating] = useState(false);
    const [editing, setEditing] = useState<PrinterProfileRow | null>(null);
    const [archiving, setArchiving] = useState<PrinterProfileRow | null>(null);

    const visible = useMemo(
        () => printers.filter((printer) => MATCHERS[state](printer)),
        [printers, state],
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
                    printers.filter(matches).length,
                ]),
            ),
        [printers],
    );

    const columns: Column<PrinterProfileRow>[] = [
        {
            key: 'name',
            header: 'Impressora',
            cell: (printer) => (
                <div>
                    <span className="font-medium">{printer.name}</span>
                    {printer.isDefault && (
                        <span className="ml-2 rounded-full bg-primary/10 px-2 py-0.5 text-xs text-primary">
                            Predefinida
                        </span>
                    )}
                    <span className="mt-0.5 block text-xs text-muted-foreground">
                        {printer.notes || '—'}
                    </span>
                </div>
            ),
        },
        {
            key: 'power',
            header: 'Potência',
            className: 'text-right tabular-nums text-muted-foreground',
            cell: (printer) => `${printer.averagePowerWatts} W`,
        },
        {
            key: 'amortisation',
            header: 'Compra / vida útil',
            className: 'text-right tabular-nums text-muted-foreground',
            cell: (printer) =>
                `${formatCents(printer.purchasePriceCents)} / ${printer.lifetimeHours} h`,
        },
        {
            key: 'maintenance',
            header: 'Manutenção',
            className: 'text-right tabular-nums text-muted-foreground',
            cell: (printer) =>
                `${formatMicros(printer.maintenanceMicrosPerHour)}/h`,
        },
        {
            // Derivado das três colunas anteriores mais a tarifa da luz — não é
            // um número que alguém escreva.
            key: 'rate',
            header: 'Custo / hora',
            className: 'text-right tabular-nums',
            cell: (printer) => formatMicros(printer.hourlyCostMicros),
        },
        {
            key: 'example',
            header: 'Peça de 1h30',
            className: 'text-right tabular-nums text-muted-foreground',
            cell: (printer) =>
                formatMicros(
                    Math.round(
                        (printer.hourlyCostMicros * EXAMPLE_MINUTES) / 60,
                    ),
                ),
        },
        {
            key: 'variants',
            header: 'Variantes',
            className: 'text-right tabular-nums',
            cell: (printer) =>
                printer.variantsCount === 0 ? (
                    <span className="text-muted-foreground">—</span>
                ) : (
                    printer.variantsCount
                ),
        },
        {
            key: 'state',
            header: 'Estado',
            cell: (printer) => (
                <StatusBadge
                    value={printer.state}
                    label={label(PRINTER_STATES, printer.state)}
                />
            ),
        },
        {
            key: 'actions',
            header: '',
            className: 'text-right',
            cell: (printer) => (
                <div className="flex justify-end gap-2">
                    {printer.active && !printer.isDefault && (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() =>
                                router.patch(
                                    predefinida(printer.id).url,
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Tornar predefinida
                        </Button>
                    )}
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => setEditing(printer)}
                    >
                        Editar
                    </Button>
                    {printer.active ? (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => setArchiving(printer)}
                        >
                            Arquivar
                        </Button>
                    ) : (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() =>
                                router.patch(
                                    restaurar(printer.id).url,
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
            <Head title="Impressoras" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Impressoras"
                    description="O que custa ter cada máquina a trabalhar uma hora — a parcela do preço que cresce com o tempo de impressão."
                >
                    <Button variant="outline" asChild>
                        <Link href={calculadora()}>Calculadora</Link>
                    </Button>
                    <Button onClick={() => setCreating(true)}>
                        Nova impressora
                    </Button>
                </PageHeader>

                <div className="grid gap-3 sm:grid-cols-3">
                    <StatCard
                        label="Impressoras ativas"
                        value={String(stats.activeCount)}
                    />
                    <StatCard
                        label="Predefinida"
                        value={
                            stats.defaultHourlyCostMicros === null
                                ? '—'
                                : `${formatMicros(stats.defaultHourlyCostMicros)}/h`
                        }
                        hint={
                            stats.defaultName ??
                            'Sem impressora: a calculadora usa a máquina por omissão do ficheiro de configuração.'
                        }
                        tone={
                            stats.defaultName === null ? 'warning' : 'default'
                        }
                    />
                    <StatCard
                        label="Custo médio por hora"
                        value={formatMicros(stats.averageHourlyCostMicros)}
                    />
                </div>

                <div className="flex flex-wrap items-center gap-3">
                    <div className="flex flex-wrap gap-2">
                        <FilterChip
                            text="Todas"
                            count={counts[ALL]}
                            active={state === ALL}
                            onClick={() => setState(ALL)}
                        />
                        {PRINTER_STATES.map((option) => (
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
                        {visible.length === 1 ? 'impressora' : 'impressoras'}
                    </span>
                </div>

                <AdminTable
                    columns={columns}
                    rows={visible}
                    rowKey={(printer) => printer.id}
                    rowClassName={(printer) =>
                        printer.active ? '' : 'opacity-60'
                    }
                    empty={
                        printers.length === 0
                            ? 'Ainda não há impressoras — cria a primeira para a calculadora saber quanto custa uma hora de máquina.'
                            : 'Nenhuma impressora com estes filtros.'
                    }
                />
            </div>

            {/*
             * A `key` muda com o alvo para forçar o remonte: o `useForm` do
             * Inertia só lê os valores iniciais no primeiro render, e sem isto
             * abrir o editar de uma impressora logo a seguir a outra mostrava
             * os dados da anterior.
             */}
            <PrinterCreateDialog
                key={editing?.id ?? 'new'}
                open={creating || editing !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setCreating(false);
                        setEditing(null);
                    }
                }}
                editing={editing}
                isFirst={printers.length === 0}
                electricityPriceMicrosPerKwh={electricityPriceMicrosPerKwh}
            />

            <ConfirmDialog
                open={archiving !== null}
                onOpenChange={(open) => !open && setArchiving(null)}
                title="Arquivar impressora"
                description={
                    <>
                        A <strong>{archiving?.name}</strong> deixa de aparecer
                        na calculadora e no formulário das variantes.
                        {archiving && archiving.variantsCount > 0 && (
                            <>
                                {' '}
                                As {archiving.variantsCount} variantes que já a
                                usam ficam intactas.
                            </>
                        )}
                        {archiving?.isDefault && (
                            <>
                                {' '}
                                Como é a predefinida, a predefinição passa para
                                a primeira que sobrar.
                            </>
                        )}
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

PrintersIndex.layout = {
    breadcrumbs: [
        { title: 'Backoffice', href: '/admin' },
        { title: 'Impressoras', href: index() },
    ],
};
