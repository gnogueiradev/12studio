import { Head, Link, router, usePoll } from '@inertiajs/react';
import { Inbox, Search } from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@/components/admin/page-header';
import { Pagination } from '@/components/admin/pagination';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import {
    CONVERSATION_STATUS_LABELS,
    CONVERSATION_STATUS_TONES,
    CONVERSATION_TYPE_LABELS,
} from '@/lib/conversations';
import { cn } from '@/lib/utils';
import { index, show } from '@/routes/admin/conversas';
import type {
    ConversationRow,
    ConversationStatus,
    ConversationType,
} from '@/types/conversation';
import type { Paginated } from '@/types/pagination';

type Props = {
    conversations: Paginated<ConversationRow>;
    filters: {
        estado: ConversationStatus | null;
        tipo: ConversationType | null;
        q: string;
    };
    counts: Record<ConversationStatus, number>;
};

const FILTER_LABELS: Record<ConversationStatus, string> = {
    open: 'Por responder',
    answered: 'Respondidas',
    closed: 'Fechadas',
};

const dateTime = new Intl.DateTimeFormat('pt-PT', {
    day: 'numeric',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
});

/**
 * Conversas: a caixa de entrada das mensagens da montra. As que esperam pela
 * loja primeiro.
 */
export default function ConversationsIndex({
    conversations,
    filters,
    counts,
}: Props) {
    const [query, setQuery] = useState(filters.q);

    // Conversas novas aparecem sem recarregar (e o contador da sidebar).
    usePoll(30_000, { only: ['conversations', 'counts', 'inbox'] });

    const visit = (next: Partial<Props['filters']>) =>
        router.get(
            index().url,
            Object.fromEntries(
                Object.entries({ ...filters, ...next }).filter(
                    ([, value]) => value !== null && value !== '',
                ),
            ),
            { preserveState: true, preserveScroll: true, replace: true },
        );

    return (
        <>
            <Head title="Conversas" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Conversas"
                    description="Mensagens da montra: contacto, “Tenho interesse” e pedidos de peça personalizada. Responder aqui manda um email ao cliente com o link da conversa."
                />

                <div className="flex flex-wrap items-center gap-2">
                    <FilterChip
                        active={filters.estado === null}
                        onClick={() => visit({ estado: null })}
                    >
                        Todas
                    </FilterChip>
                    {(Object.keys(FILTER_LABELS) as ConversationStatus[]).map(
                        (status) => (
                            <FilterChip
                                key={status}
                                active={filters.estado === status}
                                onClick={() => visit({ estado: status })}
                            >
                                {FILTER_LABELS[status]}{' '}
                                <span className="text-muted-foreground">
                                    {counts[status]}
                                </span>
                            </FilterChip>
                        ),
                    )}
                    <span className="mx-1 h-5 w-px bg-border" />
                    {(
                        Object.keys(
                            CONVERSATION_TYPE_LABELS,
                        ) as ConversationType[]
                    ).map((type) => (
                        <FilterChip
                            key={type}
                            active={filters.tipo === type}
                            onClick={() =>
                                visit({
                                    tipo: filters.tipo === type ? null : type,
                                })
                            }
                        >
                            {CONVERSATION_TYPE_LABELS[type]}
                        </FilterChip>
                    ))}
                    <form
                        className="relative ml-auto w-full sm:w-64"
                        onSubmit={(event) => {
                            event.preventDefault();
                            visit({ q: query });
                        }}
                    >
                        <Search
                            aria-hidden
                            className="absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                        />
                        <Input
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            placeholder="Nome, email ou assunto"
                            aria-label="Procurar conversas"
                            className="pl-8"
                        />
                    </form>
                </div>

                {conversations.data.length === 0 ? (
                    <div className="flex flex-col items-center gap-2 py-16 text-center text-sm text-muted-foreground">
                        <Inbox aria-hidden className="size-8" />
                        Nenhuma conversa
                        {filters.q || filters.estado || filters.tipo
                            ? ' com estes filtros'
                            : ' ainda'}
                        .
                    </div>
                ) : (
                    <ul className="divide-y divide-border/60 overflow-hidden rounded-xl border border-border/60 bg-card">
                        {conversations.data.map((conversation) => (
                            <li key={conversation.id}>
                                <Link
                                    href={show(conversation.id)}
                                    className="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 hover:bg-muted/50"
                                >
                                    <span
                                        aria-hidden
                                        className={cn(
                                            'size-2 shrink-0 rounded-full',
                                            conversation.unread
                                                ? 'bg-gold'
                                                : 'bg-transparent',
                                        )}
                                    />
                                    <div className="min-w-0 flex-1">
                                        <p
                                            className={cn(
                                                'truncate',
                                                conversation.unread &&
                                                    'font-semibold',
                                            )}
                                        >
                                            {conversation.name}
                                            <span className="font-normal text-muted-foreground">
                                                {' '}
                                                · {conversation.subject}
                                            </span>
                                            {conversation.unread && (
                                                <span className="sr-only">
                                                    (por ler)
                                                </span>
                                            )}
                                        </p>
                                        <p className="truncate text-sm text-muted-foreground">
                                            {conversation.reference} ·{' '}
                                            {conversation.email}
                                        </p>
                                    </div>
                                    <Badge
                                        variant="outline"
                                        className={cn(
                                            'border-transparent',
                                            CONVERSATION_STATUS_TONES[
                                                conversation.status
                                            ],
                                        )}
                                    >
                                        {
                                            CONVERSATION_STATUS_LABELS[
                                                conversation.status
                                            ]
                                        }
                                    </Badge>
                                    <time
                                        className="w-28 text-right text-sm text-muted-foreground"
                                        dateTime={
                                            conversation.lastMessageAt ??
                                            undefined
                                        }
                                    >
                                        {conversation.lastMessageAt
                                            ? dateTime.format(
                                                  new Date(
                                                      conversation.lastMessageAt,
                                                  ),
                                              )
                                            : ''}
                                    </time>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}

                <Pagination page={conversations} noun="conversas" />
            </div>
        </>
    );
}

function FilterChip({
    active,
    onClick,
    children,
}: {
    active: boolean;
    onClick: () => void;
    children: React.ReactNode;
}) {
    return (
        <button
            type="button"
            aria-pressed={active}
            onClick={onClick}
            className={cn(
                'rounded-full border px-3 py-1 text-sm transition-colors',
                active
                    ? 'border-foreground bg-foreground text-background'
                    : 'border-border hover:bg-muted',
            )}
        >
            {children}
        </button>
    );
}

ConversationsIndex.layout = {
    breadcrumbs: [{ title: 'Conversas', href: '/admin/conversas' }],
};
