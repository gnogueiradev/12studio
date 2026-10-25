import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    ArrowLeft,
    ClipboardList,
    Lock,
    Mail,
    Paperclip,
    Phone,
    RotateCcw,
    Send,
    X,
    XCircle,
} from 'lucide-react';
import { useRef } from 'react';
import { Panel } from '@/components/admin/panel';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import {
    CONVERSATION_STATUS_LABELS,
    CONVERSATION_STATUS_TONES,
    CONVERSATION_TYPE_LABELS,
} from '@/lib/conversations';
import { cn } from '@/lib/utils';
import {
    fechar,
    index,
    reabrir,
    rascunho,
    responder,
} from '@/routes/admin/conversas';
import { edit as editDraft } from '@/routes/admin/encomendas/rascunhos';
import type { AdminConversation } from '@/types/conversation';

const ACCEPT = '.stl,.3mf,.obj,.step,.stp,.jpg,.jpeg,.png,.webp,.pdf';

const dateTime = new Intl.DateTimeFormat('pt-PT', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
});

const DETAIL_LABELS: Record<string, string> = {
    purpose: 'Para que serve',
    dimensions: 'Medidas',
    quantity: 'Quantidade',
    color: 'Cor',
    material: 'Material',
    deadline: 'Data limite',
};

/**
 * Uma conversa no backoffice: o fio (com as notas internas), a resposta, o
 * estado e, à direita, o cliente, o produto e o "Criar rascunho de
 * encomenda".
 */
export default function ConversationShow({
    conversation,
}: {
    conversation: AdminConversation;
}) {
    const fileInput = useRef<HTMLInputElement>(null);
    const form = useForm<{
        message: string;
        internal: boolean;
        files: File[];
    }>({ message: '', internal: false, files: [] });
    const errors = form.errors as Record<string, string | undefined>;

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.post(responder(conversation.id).url, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    const action = (url: string) =>
        router.post(url, {}, { preserveScroll: true });

    return (
        <>
            <Head title={`${conversation.reference} · ${conversation.name}`} />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center gap-3">
                    <Button variant="ghost" size="icon" asChild>
                        <Link href={index()} aria-label="Voltar às conversas">
                            <ArrowLeft />
                        </Link>
                    </Button>
                    <div className="min-w-0 flex-1">
                        <h1 className="truncate text-xl font-semibold">
                            {conversation.subject}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {conversation.reference} ·{' '}
                            {CONVERSATION_TYPE_LABELS[conversation.type]} ·{' '}
                            {conversation.locale.toUpperCase()}
                        </p>
                    </div>
                    <Badge
                        variant="outline"
                        className={cn(
                            'border-transparent',
                            CONVERSATION_STATUS_TONES[conversation.status],
                        )}
                    >
                        {CONVERSATION_STATUS_LABELS[conversation.status]}
                    </Badge>
                    {conversation.status === 'closed' ? (
                        <Button
                            variant="outline"
                            onClick={() => action(reabrir(conversation.id).url)}
                        >
                            <RotateCcw />
                            Reabrir
                        </Button>
                    ) : (
                        <Button
                            variant="outline"
                            onClick={() => action(fechar(conversation.id).url)}
                        >
                            <XCircle />
                            Fechar
                        </Button>
                    )}
                </div>

                <div className="grid gap-4 lg:grid-cols-[1fr_320px]">
                    <div className="flex min-w-0 flex-col gap-4">
                        <ol className="flex flex-col gap-3">
                            {conversation.messages.map((message) => (
                                <li
                                    key={message.id}
                                    className={cn(
                                        'flex max-w-[85%] flex-col gap-1',
                                        message.author === 'staff'
                                            ? 'items-end self-end'
                                            : 'items-start self-start',
                                    )}
                                >
                                    <p className="text-xs text-muted-foreground">
                                        {message.authorName} ·{' '}
                                        {message.createdAt
                                            ? dateTime.format(
                                                  new Date(message.createdAt),
                                              )
                                            : ''}
                                        {message.internal && (
                                            <span className="ml-1 inline-flex items-center gap-1 text-warning">
                                                <Lock className="size-3" />
                                                nota interna
                                            </span>
                                        )}
                                    </p>
                                    <div
                                        className={cn(
                                            'rounded-2xl px-4 py-3 text-sm whitespace-pre-line',
                                            message.internal
                                                ? 'border border-dashed border-warning bg-warning-soft text-warning-soft-foreground'
                                                : message.author === 'staff'
                                                  ? 'rounded-br-sm bg-primary text-primary-foreground'
                                                  : 'rounded-bl-sm border border-border/60 bg-card',
                                        )}
                                    >
                                        {message.body}
                                    </div>
                                    {message.attachments.length > 0 && (
                                        <ul className="flex flex-wrap gap-2">
                                            {message.attachments.map(
                                                (attachment) => (
                                                    <li key={attachment.id}>
                                                        <a
                                                            href={
                                                                attachment.url
                                                            }
                                                            className="flex items-center gap-1.5 rounded-md border border-border/60 px-2 py-1 text-xs hover:bg-muted"
                                                        >
                                                            <Paperclip className="size-3.5" />
                                                            {attachment.name}
                                                            <span className="text-muted-foreground">
                                                                {(
                                                                    attachment.size /
                                                                    1024 /
                                                                    1024
                                                                ).toFixed(
                                                                    1,
                                                                )}{' '}
                                                                MB
                                                            </span>
                                                        </a>
                                                    </li>
                                                ),
                                            )}
                                        </ul>
                                    )}
                                </li>
                            ))}
                        </ol>

                        <form
                            onSubmit={submit}
                            className="flex flex-col gap-2 rounded-xl border border-border/60 bg-card p-3"
                        >
                            <Label htmlFor="reply" className="sr-only">
                                Resposta
                            </Label>
                            <Textarea
                                id="reply"
                                rows={4}
                                maxLength={5000}
                                placeholder={
                                    form.data.internal
                                        ? 'Nota só para a equipa…'
                                        : `Responder a ${conversation.name}…`
                                }
                                value={form.data.message}
                                onChange={(event) =>
                                    form.setData('message', event.target.value)
                                }
                            />
                            <InputError message={errors.message} />
                            <InputError
                                message={
                                    errors.files ??
                                    Object.entries(errors).find(([key]) =>
                                        key.startsWith('files.'),
                                    )?.[1]
                                }
                            />
                            {form.data.files.length > 0 && (
                                <ul className="flex flex-wrap gap-2">
                                    {form.data.files.map((file, i) => (
                                        <li
                                            key={`${file.name}-${i}`}
                                            className="flex items-center gap-1 rounded-md bg-muted px-2 py-1 text-xs"
                                        >
                                            {file.name}
                                            <button
                                                type="button"
                                                aria-label={`Tirar ${file.name}`}
                                                onClick={() =>
                                                    form.setData(
                                                        'files',
                                                        form.data.files.filter(
                                                            (_, j) => j !== i,
                                                        ),
                                                    )
                                                }
                                            >
                                                <X className="size-3.5" />
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            )}
                            <div className="flex flex-wrap items-center gap-3">
                                <input
                                    ref={fileInput}
                                    type="file"
                                    multiple
                                    accept={ACCEPT}
                                    className="sr-only"
                                    aria-label="Anexar ficheiros"
                                    onChange={(event) => {
                                        form.setData(
                                            'files',
                                            [
                                                ...form.data.files,
                                                ...Array.from(
                                                    event.target.files ?? [],
                                                ),
                                            ].slice(0, 3),
                                        );
                                        event.target.value = '';
                                    }}
                                />
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => fileInput.current?.click()}
                                >
                                    <Paperclip />
                                    Anexar
                                </Button>
                                <Label className="flex items-center gap-2 text-sm font-normal">
                                    <Checkbox
                                        checked={form.data.internal}
                                        onCheckedChange={(checked) =>
                                            form.setData(
                                                'internal',
                                                checked === true,
                                            )
                                        }
                                    />
                                    Nota interna (o cliente não vê)
                                </Label>
                                <span className="flex-1" />
                                <Button
                                    type="submit"
                                    disabled={
                                        form.processing ||
                                        form.data.message.trim() === ''
                                    }
                                >
                                    {form.processing ? <Spinner /> : <Send />}
                                    {form.data.internal
                                        ? 'Guardar nota'
                                        : 'Responder'}
                                </Button>
                            </div>
                        </form>
                    </div>

                    <aside className="flex flex-col gap-4">
                        <Panel title="Cliente">
                            <dl className="grid gap-2 text-sm">
                                <div>
                                    <dt className="text-muted-foreground">
                                        Nome
                                    </dt>
                                    <dd>{conversation.name}</dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">
                                        Email
                                    </dt>
                                    <dd>
                                        <a
                                            href={`mailto:${conversation.email}`}
                                            className="inline-flex items-center gap-1 underline"
                                        >
                                            <Mail className="size-3.5" />
                                            {conversation.email}
                                        </a>
                                    </dd>
                                </div>
                                {conversation.phone && (
                                    <div>
                                        <dt className="text-muted-foreground">
                                            Telefone
                                        </dt>
                                        <dd>
                                            <a
                                                href={`tel:${conversation.phone}`}
                                                className="inline-flex items-center gap-1 underline"
                                            >
                                                <Phone className="size-3.5" />
                                                {conversation.phone}
                                            </a>
                                        </dd>
                                    </div>
                                )}
                            </dl>
                        </Panel>

                        {(conversation.product ||
                            conversation.details ||
                            conversation.personalization) && (
                            <Panel title="Pedido">
                                {conversation.product && (
                                    <Link
                                        href={conversation.product.href}
                                        className="mb-3 flex items-center gap-3 rounded-lg border border-border/60 p-2 hover:bg-muted"
                                    >
                                        {conversation.product.image && (
                                            <img
                                                src={conversation.product.image}
                                                alt=""
                                                className="size-12 rounded object-cover"
                                            />
                                        )}
                                        <div className="min-w-0 text-sm">
                                            <p className="font-medium">
                                                {conversation.product.name}
                                            </p>
                                            {conversation.product.variant && (
                                                <p className="text-muted-foreground">
                                                    {
                                                        conversation.product
                                                            .variant
                                                    }
                                                </p>
                                            )}
                                        </div>
                                    </Link>
                                )}
                                <dl className="grid gap-2 text-sm">
                                    {conversation.quantity !== null && (
                                        <Row label="Quantidade">
                                            {conversation.quantity}
                                        </Row>
                                    )}
                                    {Object.entries(
                                        conversation.personalization ?? {},
                                    ).map(([key, value]) => (
                                        <Row
                                            key={key}
                                            label={`Personalização (${key})`}
                                        >
                                            {value}
                                        </Row>
                                    ))}
                                    {Object.entries(
                                        conversation.details ?? {},
                                    ).map(([key, value]) => (
                                        <Row
                                            key={key}
                                            label={DETAIL_LABELS[key] ?? key}
                                        >
                                            {String(value)}
                                        </Row>
                                    ))}
                                </dl>
                            </Panel>
                        )}

                        <Panel
                            title="Encomenda"
                            description="Passa o cliente, a peça e a quantidade para um rascunho de encomenda manual. O preço e o envio acertam-se lá."
                        >
                            {conversation.orderDraftId ? (
                                <Button variant="outline" asChild>
                                    <Link
                                        href={editDraft(
                                            conversation.orderDraftId,
                                        )}
                                    >
                                        <ClipboardList />
                                        Abrir o rascunho
                                    </Link>
                                </Button>
                            ) : (
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        router.post(
                                            rascunho(conversation.id).url,
                                        )
                                    }
                                >
                                    <ClipboardList />
                                    Criar rascunho de encomenda
                                </Button>
                            )}
                        </Panel>
                    </aside>
                </div>
            </div>
        </>
    );
}

function Row({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div>
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="whitespace-pre-line">{children}</dd>
        </div>
    );
}

ConversationShow.layout = {
    breadcrumbs: [{ title: 'Conversas', href: '/admin/conversas' }],
};
