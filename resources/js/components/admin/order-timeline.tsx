import { useState } from 'react';
import { formatCents } from '@/lib/money';
import { label } from '@/lib/options';
import { cn } from '@/lib/utils';
import type { TimelineEntry } from '@/types/order';
import {
    ORDER_STATUSES,
    PAYMENT_STATUSES,
    PRODUCTION_STATUSES,
} from '@/types/order';

type Filter = 'all' | 'state' | 'payment' | 'item';

const FILTERS: { key: Filter; label: string }[] = [
    { key: 'all', label: 'Tudo' },
    { key: 'state', label: 'Estado' },
    { key: 'payment', label: 'Pagamento' },
    { key: 'item', label: 'Artigos' },
];

/** Os títulos de cada degrau, escritos como acontecimento e não como estado. */
const STATE_TITLES: Record<string, string> = {
    pending_payment: 'Encomenda registada',
    paid: 'Pagamento confirmado',
    in_production: 'Itens enviados para produção',
    ready_to_ship: 'Todos os itens prontos',
    shipped: 'Encomenda enviada',
    delivered: 'Encomenda entregue',
    cancelled: 'Encomenda cancelada',
    refunded: 'Encomenda reembolsada',
};

/**
 * Notas que o OrderService escreve sozinho nas transições automáticas. Dizem
 * o mesmo que o título do evento — mostrá-las por baixo era ler tudo duas
 * vezes (e sem acentos).
 */
const AUTOMATIC_NOTES = new Set([
    'Encomenda registada no backoffice.',
    'Pagamento confirmado.',
    'Itens enviados para producao.',
    'Sem producao necessaria.',
    'Todos os itens prontos.',
    'Um item voltou a producao.',
    'Pagamento falhou.',
    'Pagamento reembolsado.',
]);

type Event = {
    id: string;
    category: 'state' | 'payment' | 'item';
    day: string | null;
    time: string | null;
    title: string;
    chip: string | null;
    note: string | null;
    author: string | null;
    /** Só nos passos de artigos agrupados: um caminho por artigo. */
    detail: string[];
};

function stateEvent(entry: TimelineEntry): Event {
    const base = {
        id: entry.id,
        day: entry.day,
        time: entry.time,
        author: entry.author,
        detail: [],
    };

    if (entry.category === 'payment') {
        return {
            ...base,
            category: 'payment',
            title: `Pagamento: ${label(PAYMENT_STATUSES, entry.paymentFrom).toLowerCase()} → ${label(PAYMENT_STATUSES, entry.paymentTo).toLowerCase()}`,
            chip: label(PAYMENT_STATUSES, entry.paymentTo),
            note: entry.note,
        };
    }

    if (entry.category === 'adjustment') {
        const delta = entry.toCents - entry.fromCents;

        return {
            ...base,
            category: 'payment',
            title: `Total ajustado de ${formatCents(entry.fromCents)} para ${formatCents(entry.toCents)}`,
            chip: `${delta > 0 ? '+' : ''}${formatCents(delta)}`,
            note: entry.note,
        };
    }

    // O único recuo do pipeline: um artigo voltou à bancada.
    const reopened =
        entry.fromStatus === 'ready_to_ship' &&
        entry.toStatus === 'in_production';

    return {
        ...base,
        category: 'state',
        title: reopened
            ? 'Um artigo voltou a produção'
            : (STATE_TITLES[entry.toStatus] ??
              label(ORDER_STATUSES, entry.toStatus)),
        chip: label(ORDER_STATUSES, entry.toStatus),
        note:
            entry.note !== null && AUTOMATIC_NOTES.has(entry.note)
                ? null
                : entry.note,
    };
}

/**
 * Passos de produção seguidos colam-se num só evento. Levar seis artigos até
 * "Pronto" são dezoito linhas que dizem uma coisa só; o detalhe por artigo
 * continua a um clique.
 */
function itemGroupEvent(
    group: Extract<TimelineEntry, { kind: 'item' }>[],
): Event {
    // O histórico vem do mais recente para o mais antigo; o caminho lê-se ao
    // contrário.
    const chronological = [...group].reverse();
    const paths = new Map<number, { name: string; steps: string[] }>();

    for (const entry of chronological) {
        const path = paths.get(entry.itemId) ?? {
            name: entry.subject,
            steps: [],
        };
        path.steps.push(label(PRODUCTION_STATUSES, entry.toStatus));
        paths.set(entry.itemId, path);
    }

    const allSteps = [
        ...new Set(
            chronological.map((entry) =>
                label(PRODUCTION_STATUSES, entry.toStatus),
            ),
        ),
    ];
    const newest = group[0];
    const notes = group.map((entry) => entry.note).filter(Boolean);

    if (paths.size === 1) {
        const [only] = paths.values();

        return {
            id: newest.id,
            category: 'item',
            day: newest.day,
            time: newest.time,
            title: `${only.name} → ${only.steps.join(' → ')}`,
            chip: group.length > 1 ? `${group.length} passos` : null,
            note: notes.length === 1 ? notes[0] : null,
            author: newest.author,
            detail: [],
        };
    }

    return {
        id: newest.id,
        category: 'item',
        day: newest.day,
        time: newest.time,
        title: `${paths.size} artigos percorreram ${allSteps.join(' → ')}`,
        chip: `${group.length} passos`,
        note: null,
        author: newest.author,
        detail: [...paths.values()].map(
            (path) => `${path.name} — ${path.steps.join(' → ')}`,
        ),
    };
}

function toEvents(entries: TimelineEntry[]): Event[] {
    const events: Event[] = [];
    let group: Extract<TimelineEntry, { kind: 'item' }>[] = [];

    const flush = () => {
        if (group.length > 0) {
            events.push(itemGroupEvent(group));
            group = [];
        }
    };

    for (const entry of entries) {
        if (entry.kind === 'item') {
            group.push(entry);
            continue;
        }

        flush();
        events.push(stateEvent(entry));
    }

    flush();

    return events;
}

const DOT: Record<Event['category'], string> = {
    state: 'bg-success',
    payment: 'bg-gold',
    item: 'bg-muted-foreground',
};

type Props = {
    entries: TimelineEntry[];
};

export function OrderTimeline({ entries }: Props) {
    const [filter, setFilter] = useState<Filter>('all');
    const [expanded, setExpanded] = useState<Record<string, boolean>>({});

    const events = toEvents(entries).filter(
        (event) => filter === 'all' || event.category === filter,
    );

    return (
        <section className="rounded-xl border border-border/60 bg-card">
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-border/60 px-4 py-3.5">
                <h2 className="text-sm font-semibold">Histórico</h2>
                <div className="flex gap-1.5 print:hidden">
                    {FILTERS.map((option) => (
                        <button
                            key={option.key}
                            type="button"
                            aria-pressed={filter === option.key}
                            onClick={() => setFilter(option.key)}
                            className={cn(
                                'rounded-full border px-3 py-1 text-xs font-medium transition-colors',
                                filter === option.key
                                    ? 'border-border bg-secondary text-foreground'
                                    : 'border-border/60 text-muted-foreground hover:bg-secondary/60',
                            )}
                        >
                            {option.label}
                        </button>
                    ))}
                </div>
            </div>

            {events.length === 0 ? (
                <p className="px-4 py-5 text-sm text-muted-foreground">
                    Sem movimentos registados.
                </p>
            ) : (
                <ol className="flex flex-col px-4 pt-1.5 pb-4">
                    {events.map((event, index) => {
                        const showDay =
                            event.day !== null &&
                            event.day !== events[index - 1]?.day;
                        const isOpen = expanded[event.id] === true;

                        return (
                            <li key={event.id} className="flex flex-col">
                                {showDay && (
                                    <span className="pt-3 pb-1 text-[11px] font-medium tracking-wider text-muted-foreground uppercase">
                                        {event.day}
                                    </span>
                                )}
                                <div className="grid grid-cols-[3rem_1.25rem_minmax(0,1fr)] gap-3 py-2.5">
                                    <span className="pt-0.5 text-xs text-muted-foreground tabular-nums">
                                        {event.time}
                                    </span>
                                    <span className="flex flex-col items-center gap-1">
                                        <span
                                            className={cn(
                                                'mt-1.5 size-2 rounded-full',
                                                DOT[event.category],
                                            )}
                                        />
                                        <span className="w-px flex-1 bg-border/60" />
                                    </span>
                                    <div className="flex min-w-0 flex-col gap-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="text-sm text-pretty">
                                                {event.title}
                                            </span>
                                            {event.chip && (
                                                <span className="rounded-full border border-border/60 px-2 py-px text-[11px] text-muted-foreground">
                                                    {event.chip}
                                                </span>
                                            )}
                                        </div>
                                        {event.note && (
                                            <p className="text-[13px] text-muted-foreground">
                                                {event.note}
                                            </p>
                                        )}
                                        {event.author && (
                                            <p className="text-[11px] text-muted-foreground">
                                                por {event.author}
                                            </p>
                                        )}
                                        {event.detail.length > 0 && (
                                            <button
                                                type="button"
                                                aria-expanded={isOpen}
                                                onClick={() =>
                                                    setExpanded((current) => ({
                                                        ...current,
                                                        [event.id]: !isOpen,
                                                    }))
                                                }
                                                className="self-start text-[13px] font-medium underline-offset-4 hover:underline print:hidden"
                                            >
                                                {isOpen
                                                    ? 'Esconder detalhe'
                                                    : 'Ver detalhe por artigo'}
                                            </button>
                                        )}
                                        {isOpen && (
                                            <ul className="mt-0.5 flex flex-col gap-1.5 rounded-r-lg border-l-2 border-border bg-secondary/50 px-3 py-2.5">
                                                {event.detail.map((line) => (
                                                    <li
                                                        key={line}
                                                        className="text-[13px]"
                                                    >
                                                        {line}
                                                    </li>
                                                ))}
                                            </ul>
                                        )}
                                    </div>
                                </div>
                            </li>
                        );
                    })}
                </ol>
            )}
        </section>
    );
}
