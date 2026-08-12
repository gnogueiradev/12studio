import { Head, router } from '@inertiajs/react';
import { MoveHorizontal } from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@/components/admin/page-header';
import { ProductionCard } from '@/components/admin/production-card';
import { label } from '@/lib/options';
import { cn } from '@/lib/utils';
import { producao } from '@/routes/admin/itens';
import type { ProductionCard as Card } from '@/types/order';
import { PRODUCTION_BOARD_COLUMNS, PRODUCTION_STATUSES } from '@/types/order';

type Props = {
    items: Card[];
};

/**
 * Ponto de cor por coluna. Não reaproveita o `TONES` do StatusBadge de
 * propósito: lá `printing` e `quality_check` partilham o tom `info`, o que
 * num quadro de quatro colunas lado a lado daria dois pontos iguais.
 */
const COLUMN_DOT: Record<string, string> = {
    awaiting_production: 'bg-brand-taupe',
    printing: 'bg-warning',
    quality_check: 'bg-info',
    ready: 'bg-success',
};

export default function ProductionBoard({ items }: Props) {
    const [pendingId, setPendingId] = useState<number | null>(null);
    const [dragging, setDragging] = useState<Card | null>(null);
    const [overColumn, setOverColumn] = useState<string | null>(null);

    /**
     * Um único caminho para as setas e para o drag & drop. Não há validação
     * de recuo aqui: a regra vive no OrderService, e o erro volta pelo
     * toast de flash como em todo o backoffice.
     */
    const move = (card: Card, to: string) => {
        if (to === card.productionStatus) {
            return;
        }

        setPendingId(card.id);
        router.patch(
            producao(card.id).url,
            { production_status: to },
            { preserveScroll: true, onFinish: () => setPendingId(null) },
        );
    };

    const drop = (column: string) => {
        setOverColumn(null);

        if (dragging) {
            move(dragging, column);
            setDragging(null);
        }
    };

    const printing = items.filter(
        (item) => item.productionStatus === 'printing',
    );

    return (
        <>
            <Head title="Produção" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Produção"
                    description="Um cartão por artigo a imprimir. A encomenda avança para expedição sozinha quando o último ficar pronto."
                >
                    <span className="flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs text-muted-foreground">
                        <span
                            aria-hidden
                            className={cn(
                                'size-1.5 rounded-full',
                                printing.length > 0
                                    ? 'bg-success'
                                    : 'bg-muted-foreground',
                            )}
                        />
                        {printing.length === 0
                            ? 'Impressora parada'
                            : printing.length === 1
                              ? `Impressora a trabalhar · ${printing[0].orderNumber}`
                              : `Impressora a trabalhar · ${printing.length} artigos`}
                    </span>
                </PageHeader>

                <p className="flex items-center gap-2 text-xs text-muted-foreground">
                    <MoveHorizontal
                        aria-hidden
                        className="size-3.5 text-gold"
                    />
                    Arrasta um cartão para outra coluna, ou usa as setas do
                    cartão. Um artigo pode voltar atrás enquanto a encomenda não
                    seguir.
                </p>

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    {PRODUCTION_BOARD_COLUMNS.map((column) => {
                        const columnItems = items.filter(
                            (item) => item.productionStatus === column,
                        );

                        return (
                            <section
                                key={column}
                                aria-label={label(PRODUCTION_STATUSES, column)}
                                className="flex flex-col gap-3 rounded-xl border bg-card p-3"
                            >
                                <div className="flex items-center justify-between gap-2 px-1">
                                    <h2 className="flex items-center gap-2 text-sm font-medium">
                                        <span
                                            aria-hidden
                                            className={cn(
                                                'size-2 rounded-xs',
                                                COLUMN_DOT[column],
                                            )}
                                        />
                                        {label(PRODUCTION_STATUSES, column)}
                                    </h2>
                                    <span className="text-xs text-muted-foreground tabular-nums">
                                        {columnItems.length}
                                    </span>
                                </div>

                                <div
                                    onDragOver={(event) => {
                                        // Sem preventDefault o browser recusa o drop.
                                        event.preventDefault();
                                        setOverColumn(column);
                                    }}
                                    onDragLeave={() =>
                                        setOverColumn((current) =>
                                            current === column ? null : current,
                                        )
                                    }
                                    onDrop={() => drop(column)}
                                    className={cn(
                                        'flex min-h-32 flex-col gap-3 rounded-lg p-1 transition-colors',
                                        overColumn === column &&
                                            dragging !== null &&
                                            'bg-accent ring-2 ring-gold',
                                    )}
                                >
                                    {columnItems.length === 0 ? (
                                        <p className="rounded-lg border border-dashed p-6 text-center text-xs text-muted-foreground">
                                            Nada aqui.
                                        </p>
                                    ) : (
                                        columnItems.map((item) => (
                                            <ProductionCard
                                                key={item.id}
                                                card={item}
                                                pending={pendingId === item.id}
                                                onMove={move}
                                                onDragStart={setDragging}
                                                onDragEnd={() => {
                                                    setDragging(null);
                                                    setOverColumn(null);
                                                }}
                                            />
                                        ))
                                    )}
                                </div>
                            </section>
                        );
                    })}
                </div>
            </div>
        </>
    );
}

ProductionBoard.layout = {
    breadcrumbs: [
        { title: 'Backoffice', href: '/admin' },
        { title: 'Produção', href: '/admin/producao' },
    ],
};
