import { Head, Link, useForm, usePoll } from '@inertiajs/react';
import { Lock, Paperclip, Send, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useLocale, useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { CustomerConversation } from '@/types/conversation';

const ACCEPT = '.stl,.3mf,.obj,.step,.stp,.jpg,.jpeg,.png,.webp,.pdf';
const POLL_MS = 15_000;

/**
 * A conversa do cliente com a loja, pelo link privado (sem conta). As
 * mensagens dele à direita, as da loja à esquerda. Atualiza sozinha a cada
 * ~15 s enquanto o separador está visível.
 */
export default function ConversationPage({
    conversation,
}: {
    conversation: CustomerConversation;
}) {
    const t = useT();
    const { intl } = useLocale();
    const bottom = useRef<HTMLDivElement>(null);
    const fileInput = useRef<HTMLInputElement>(null);

    usePoll(POLL_MS, { only: ['conversation'] });

    const form = useForm<{ message: string; files: File[] }>({
        message: '',
        files: [],
    });
    const errors = form.errors as Record<string, string | undefined>;

    // "há 5 minutos": o relógio anda de minuto a minuto.
    const [now, setNow] = useState(() => Date.now());
    useEffect(() => {
        const timer = window.setInterval(() => setNow(Date.now()), 60_000);

        return () => window.clearInterval(timer);
    }, []);

    const count = conversation.messages.length;
    useEffect(() => {
        bottom.current?.scrollIntoView({ block: 'end' });
    }, [count]);

    const relative = (iso: string | null) => {
        if (!iso) {
            return '';
        }

        const seconds = (new Date(iso).getTime() - now) / 1000;
        const format = new Intl.RelativeTimeFormat(intl, { numeric: 'auto' });

        for (const [unit, size] of [
            ['day', 86_400],
            ['hour', 3_600],
            ['minute', 60],
        ] as const) {
            if (Math.abs(seconds) >= size) {
                return format.format(Math.round(seconds / size), unit);
            }
        }

        return format.format(0, 'minute');
    };

    const statusLabel = t(`conversation.status_${conversation.status}`);

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.post(conversation.replyUrl, {
            forceFormData: true,
            preserveScroll: true,
            only: ['conversation', 'errors', 'flash'],
            onSuccess: () => form.reset(),
        });
    };

    return (
        <>
            <Head
                title={`${t('conversation.title')} ${conversation.reference}`}
            >
                <meta name="robots" content="noindex, nofollow" />
            </Head>

            <div className="mx-auto flex w-full max-w-3xl flex-col gap-6 px-4 py-8 md:py-12">
                <header className="flex flex-col gap-3">
                    <p className="text-sm text-muted-foreground">
                        {t(`conversation.type_${conversation.type}`)} ·{' '}
                        {conversation.reference}
                    </p>
                    <div className="flex flex-wrap items-center gap-3">
                        <h1 className="text-2xl font-semibold tracking-tight md:text-3xl">
                            {t('conversation.title')}
                        </h1>
                        <span
                            className={cn(
                                'rounded-full px-2.5 py-0.5 text-xs font-medium',
                                conversation.status === 'answered'
                                    ? 'bg-success-soft text-success-soft-foreground'
                                    : conversation.status === 'closed'
                                      ? 'bg-muted text-muted-foreground'
                                      : 'bg-warning-soft text-warning-soft-foreground',
                            )}
                        >
                            {statusLabel}
                        </span>
                    </div>
                    <p className="flex items-center gap-2 text-xs text-muted-foreground">
                        <Lock aria-hidden="true" className="size-3.5" />
                        {t('conversation.private_note')}
                    </p>
                </header>

                {conversation.product && (
                    <Link
                        href={conversation.product.href}
                        className="flex items-center gap-4 rounded-xl border border-border/60 bg-card p-3 text-card-foreground transition-colors hover:bg-accent"
                    >
                        {conversation.product.image && (
                            <img
                                src={conversation.product.image}
                                alt=""
                                className="size-16 shrink-0 rounded-md object-cover"
                            />
                        )}
                        <div className="min-w-0 text-sm">
                            <p className="text-xs text-muted-foreground">
                                {t('conversation.about')}
                            </p>
                            <p className="font-medium">
                                {conversation.product.name}
                            </p>
                            {conversation.product.variant && (
                                <p className="text-muted-foreground">
                                    {conversation.product.variant}
                                </p>
                            )}
                            {conversation.quantity !== null && (
                                <p className="text-muted-foreground">
                                    {t('conversation.quantity', {
                                        count: conversation.quantity,
                                    })}
                                </p>
                            )}
                        </div>
                    </Link>
                )}

                <ol className="flex flex-col gap-4" aria-live="polite">
                    {conversation.messages.map((message) => (
                        <li
                            key={message.id}
                            className={cn(
                                'flex max-w-[85%] flex-col gap-1',
                                message.mine
                                    ? 'items-end self-end'
                                    : 'items-start self-start',
                            )}
                        >
                            <p className="text-xs text-muted-foreground">
                                {message.mine
                                    ? t('conversation.you')
                                    : message.author}{' '}
                                ·{' '}
                                <time dateTime={message.createdAt ?? undefined}>
                                    {relative(message.createdAt)}
                                </time>
                            </p>
                            <div
                                className={cn(
                                    'rounded-2xl px-4 py-3 text-sm whitespace-pre-line',
                                    message.mine
                                        ? 'rounded-br-sm bg-primary text-primary-foreground'
                                        : 'rounded-bl-sm border border-border/60 bg-card text-card-foreground',
                                )}
                            >
                                {message.body}
                            </div>
                            {message.attachments.length > 0 && (
                                <ul className="flex flex-wrap gap-2">
                                    {message.attachments.map((attachment) => (
                                        <li key={attachment.id}>
                                            <a
                                                href={attachment.url}
                                                className="flex items-center gap-1.5 rounded-md border border-border/60 px-2 py-1 text-xs hover:bg-accent"
                                                aria-label={t(
                                                    'conversation.download',
                                                    { name: attachment.name },
                                                )}
                                            >
                                                <Paperclip
                                                    aria-hidden="true"
                                                    className="size-3.5"
                                                />
                                                {attachment.name}
                                            </a>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </li>
                    ))}
                </ol>
                <div ref={bottom} />

                {conversation.status === 'closed' && (
                    <p className="rounded-lg bg-accent p-4 text-sm text-accent-foreground">
                        {t('conversation.closed_note')}
                    </p>
                )}

                <form
                    onSubmit={submit}
                    className="sticky bottom-0 flex flex-col gap-2 border-t border-border/60 bg-background/95 py-4 backdrop-blur"
                >
                    <Label htmlFor="reply" className="sr-only">
                        {t('conversation.reply_label')}
                    </Label>
                    <Textarea
                        id="reply"
                        rows={3}
                        maxLength={5000}
                        placeholder={t('conversation.reply_placeholder')}
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
                            {form.data.files.map((file, index) => (
                                <li
                                    key={`${file.name}-${index}`}
                                    className="flex items-center gap-1 rounded-md bg-muted px-2 py-1 text-xs"
                                >
                                    {file.name}
                                    <button
                                        type="button"
                                        aria-label={t('form.remove_file', {
                                            name: file.name,
                                        })}
                                        onClick={() =>
                                            form.setData(
                                                'files',
                                                form.data.files.filter(
                                                    (_, i) => i !== index,
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
                    <div className="flex items-center gap-2">
                        <input
                            ref={fileInput}
                            type="file"
                            multiple
                            accept={ACCEPT}
                            className="sr-only"
                            aria-label={t('conversation.attach')}
                            onChange={(event) => {
                                form.setData(
                                    'files',
                                    [
                                        ...form.data.files,
                                        ...Array.from(event.target.files ?? []),
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
                            <Paperclip aria-hidden="true" />
                            {t('conversation.attach')}
                        </Button>
                        <span className="flex-1" />
                        <Button
                            type="submit"
                            disabled={
                                form.processing ||
                                form.data.message.trim() === ''
                            }
                        >
                            {form.processing ? (
                                <Spinner />
                            ) : (
                                <Send aria-hidden="true" />
                            )}
                            {t('conversation.send_reply')}
                        </Button>
                    </div>
                </form>
            </div>
        </>
    );
}
