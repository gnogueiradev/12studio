import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { label } from '@/lib/options';
import { cn } from '@/lib/utils';
import { show } from '@/routes/admin/encomendas';
import type { ProductionCard as Card } from '@/types/order';
import {
    nextProductionStatus,
    previousProductionStatus,
    PRODUCTION_STATUSES,
} from '@/types/order';

/**
 * "~45min", "~6h", "~2h30". Estimativa grosseira de propósito — o `~` diz
 * tudo, e ninguém planeia o dia ao minuto com o tempo teórico da variante.
 */
function formatMinutes(total: number): string {
    const hours = Math.floor(total / 60);
    const minutes = total % 60;

    if (hours === 0) {
        return `~${minutes}min`;
    }

    return minutes === 0
        ? `~${hours}h`
        : `~${hours}h${String(minutes).padStart(2, '0')}`;
}

function Chip({ children }: { children: React.ReactNode }) {
    return (
        <span className="rounded-full border px-2 py-0.5 text-xs text-muted-foreground">
            {children}
        </span>
    );
}

type Props = {
    card: Card;
    /** Há um PATCH deste cartão a caminho do servidor. */
    pending: boolean;
    onMove: (card: Card, to: string) => void;
    onDragStart: (card: Card) => void;
    onDragEnd: () => void;
};

export function ProductionCard({
    card,
    pending,
    onMove,
    onDragStart,
    onDragEnd,
}: Props) {
    const next = nextProductionStatus(card.productionStatus);
    const previous = previousProductionStatus(card.productionStatus);
    const isPrinting = card.productionStatus === 'printing';
    const isReady = card.productionStatus === 'ready';

    return (
        <article
            draggable={!pending}
            onDragStart={() => onDragStart(card)}
            onDragEnd={onDragEnd}
            className={cn(
                'flex cursor-grab flex-col gap-2 rounded-lg border bg-card p-3 text-sm transition-opacity active:cursor-grabbing',
                // A peça que está mesmo na impressora salta à vista no quadro.
                isPrinting && 'border-gold',
                pending && 'pointer-events-none opacity-45',
            )}
        >
            <div className="flex items-start justify-between gap-2">
                <span className="font-medium">{card.productName}</span>

                <div className="flex flex-none items-center gap-1">
                    {isReady
                        ? card.orderReadyToShip && (
                              <span
                                  aria-hidden
                                  className="text-xs font-semibold text-success"
                              >
                                  ✓
                              </span>
                          )
                        : null}

                    {/*
                        As setas não são um extra do drag & drop — são a única
                        forma de mover um cartão com o teclado.
                    */}
                    {previous !== null && (
                        <button
                            type="button"
                            aria-label={`Voltar para ${label(PRODUCTION_STATUSES, previous)}`}
                            title={`Voltar para ${label(PRODUCTION_STATUSES, previous)}`}
                            onClick={() => onMove(card, previous)}
                            className="rounded-md border p-1 text-muted-foreground transition-colors hover:border-gold hover:text-foreground"
                        >
                            <ChevronLeft className="size-3.5" />
                        </button>
                    )}
                    {next !== null && (
                        <button
                            type="button"
                            aria-label={`Avançar para ${label(PRODUCTION_STATUSES, next)}`}
                            title={`Avançar para ${label(PRODUCTION_STATUSES, next)}`}
                            onClick={() => onMove(card, next)}
                            className="rounded-md border p-1 text-muted-foreground transition-colors hover:border-gold hover:text-foreground"
                        >
                            <ChevronRight className="size-3.5" />
                        </button>
                    )}
                </div>
            </div>

            <Link
                href={show(card.orderId)}
                className="text-xs text-muted-foreground hover:underline"
            >
                {card.orderNumber} · {card.customerName}
                {card.totalInOrder > 1 &&
                    ` · ${card.positionInOrder} de ${card.totalInOrder}`}
            </Link>

            {card.personalization.length > 0 && (
                <dl className="flex flex-col gap-0.5 rounded-md bg-muted/60 p-2 text-xs">
                    {card.personalization.map((field) => (
                        <div key={field.label} className="flex gap-1">
                            <dt className="text-muted-foreground">
                                {field.label}:
                            </dt>
                            <dd className="font-medium">{field.value}</dd>
                        </div>
                    ))}
                </dl>
            )}

            {/* Na última coluna o que interessa é a encomenda, não a peça. */}
            {isReady ? (
                <p
                    className={cn(
                        'text-xs',
                        card.orderReadyToShip
                            ? 'text-success'
                            : 'text-muted-foreground',
                    )}
                >
                    {card.orderReadyToShip
                        ? 'Encomenda pronta a enviar'
                        : 'A aguardar os outros artigos'}
                </p>
            ) : (
                <div className="flex flex-wrap gap-1.5">
                    {card.variantLabel && <Chip>{card.variantLabel}</Chip>}
                    {card.qty > 1 && <Chip>{card.qty} un.</Chip>}
                    {card.estimatedMinutes !== null && (
                        <Chip>{formatMinutes(card.estimatedMinutes)}</Chip>
                    )}
                    {isPrinting && card.startedPrintingAt && (
                        <Chip>Começou {card.startedPrintingAt}</Chip>
                    )}
                </div>
            )}
        </article>
    );
}
