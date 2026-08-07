import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { PageHeader } from '@/components/admin/page-header';
import { Button } from '@/components/ui/button';
import { label } from '@/lib/options';
import { show } from '@/routes/admin/encomendas';
import { producao } from '@/routes/admin/itens';
import type { ProductionCard } from '@/types/order';
import { PRODUCTION_BOARD_COLUMNS, PRODUCTION_STATUSES } from '@/types/order';

type Props = {
    items: ProductionCard[];
};

/**
 * Avanço por botão, não drag & drop: a transição passa pelo OrderService
 * como qualquer outra, é acessível por teclado e não inventa estados.
 */
function nextStatus(current: string): string | null {
    const index = PRODUCTION_BOARD_COLUMNS.indexOf(
        current as (typeof PRODUCTION_BOARD_COLUMNS)[number],
    );

    return index === -1 || index === PRODUCTION_BOARD_COLUMNS.length - 1
        ? null
        : PRODUCTION_BOARD_COLUMNS[index + 1];
}

export default function ProductionBoard({ items }: Props) {
    const [advancing, setAdvancing] = useState<number | null>(null);

    const advance = (item: ProductionCard) => {
        const next = nextStatus(item.productionStatus);

        if (next === null) {
            return;
        }

        setAdvancing(item.id);
        router.patch(
            producao(item.id).url,
            { production_status: next },
            { preserveScroll: true, onFinish: () => setAdvancing(null) },
        );
    };

    return (
        <>
            <Head title="Produção" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Produção"
                    description="Um cartão por artigo a imprimir. A encomenda avança para expedição sozinha quando o último ficar pronto."
                />

                <div className="grid gap-4 lg:grid-cols-4">
                    {PRODUCTION_BOARD_COLUMNS.map((column) => {
                        const columnItems = items.filter(
                            (item) => item.productionStatus === column,
                        );

                        return (
                            <div key={column} className="flex flex-col gap-3">
                                <div className="flex items-center justify-between">
                                    <h2 className="text-sm font-medium">
                                        {label(PRODUCTION_STATUSES, column)}
                                    </h2>
                                    <span className="text-xs text-muted-foreground">
                                        {columnItems.length}
                                    </span>
                                </div>

                                <div className="flex min-h-24 flex-col gap-3 rounded-xl border border-dashed border-border/60 p-3">
                                    {columnItems.length === 0 ? (
                                        <p className="text-center text-xs text-muted-foreground">
                                            Nada aqui.
                                        </p>
                                    ) : (
                                        columnItems.map((item) => {
                                            const next = nextStatus(
                                                item.productionStatus,
                                            );

                                            return (
                                                <article
                                                    key={item.id}
                                                    className="flex flex-col gap-2 rounded-lg border border-border/60 bg-card p-3 text-sm"
                                                >
                                                    <div className="flex items-start justify-between gap-2">
                                                        <span className="font-medium">
                                                            {item.productName}
                                                        </span>
                                                        <span className="shrink-0 text-muted-foreground">
                                                            ×{item.qty}
                                                        </span>
                                                    </div>

                                                    {item.variantLabel && (
                                                        <span className="text-xs text-muted-foreground">
                                                            {item.variantLabel}
                                                        </span>
                                                    )}

                                                    {item.personalization
                                                        .length > 0 && (
                                                        <dl className="flex flex-col gap-0.5 rounded-md bg-muted/60 p-2 text-xs">
                                                            {item.personalization.map(
                                                                (field) => (
                                                                    <div
                                                                        key={
                                                                            field.label
                                                                        }
                                                                        className="flex gap-1"
                                                                    >
                                                                        <dt className="text-muted-foreground">
                                                                            {
                                                                                field.label
                                                                            }
                                                                            :
                                                                        </dt>
                                                                        <dd className="font-medium">
                                                                            {
                                                                                field.value
                                                                            }
                                                                        </dd>
                                                                    </div>
                                                                ),
                                                            )}
                                                        </dl>
                                                    )}

                                                    <Link
                                                        href={show(
                                                            item.orderId,
                                                        )}
                                                        className="text-xs text-muted-foreground hover:underline"
                                                    >
                                                        {item.orderNumber} ·{' '}
                                                        {item.customerName}
                                                    </Link>

                                                    {next !== null && (
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            disabled={
                                                                advancing ===
                                                                item.id
                                                            }
                                                            onClick={() =>
                                                                advance(item)
                                                            }
                                                        >
                                                            →{' '}
                                                            {label(
                                                                PRODUCTION_STATUSES,
                                                                next,
                                                            )}
                                                        </Button>
                                                    )}
                                                </article>
                                            );
                                        })
                                    )}
                                </div>
                            </div>
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
