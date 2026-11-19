import { Head, Link, router, usePoll } from '@inertiajs/react';
import { Ban, CircleCheck, Clock, Mail, TimerOff } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useLocalizedUrl, useT } from '@/lib/i18n';
import { usePrice } from '@/lib/money';
import { cn } from '@/lib/utils';
import type { PurchaseData } from '@/types/shop';

const POLL_MS = 3_000;
const GIVE_UP_MS = 120_000;

/**
 * O /compra/{token}, onde o Stripe devolve o cliente. Muitas vezes chega
 * antes do aviso do Stripe: "a confirmar" pergunta ao servidor de 3 em 3
 * segundos, para quando o estado muda, e ao fim de 2 minutos sossega o
 * cliente com o email de confirmação e um "Atualizar". A região de estado é
 * anunciada com calma e o título recebe o foco quando o estado muda.
 */
export default function PurchasePage({ purchase }: { purchase: PurchaseData }) {
    const t = useT();
    const url = useLocalizedUrl();
    const price = usePrice();
    const heading = useRef<HTMLHeadingElement>(null);
    const previous = useRef(purchase.state);

    const { state } = purchase;
    const [gaveUp, setGaveUp] = useState(false);
    const [refreshing, setRefreshing] = useState(false);
    const [saving, setSaving] = useState(false);
    const waiting = state === 'confirming' && !gaveUp;

    const { start, stop } = usePoll(
        POLL_MS,
        { only: ['purchase'] },
        { autoStart: false },
    );

    // O `usePoll` devolve `start`/`stop` novos a cada render (e cada resposta
    // do poll renderiza), mas todos falam com o mesmo poll: guardam-se os do
    // primeiro render, para o efeito só depender de `waiting`. O prazo fixa-se
    // uma vez, na primeira espera, e nenhum render o mexe.
    const controls = useRef({ start, stop });
    const deadline = useRef<number | null>(null);

    useEffect(() => {
        if (!waiting) {
            return;
        }

        deadline.current ??= Date.now() + GIVE_UP_MS;
        const poll = controls.current;
        poll.start();
        const timer = window.setTimeout(
            () => setGaveUp(true),
            Math.max(0, deadline.current - Date.now()),
        );

        return () => {
            window.clearTimeout(timer);
            poll.stop();
        };
    }, [waiting]);

    useEffect(() => {
        if (previous.current !== state) {
            previous.current = state;
            heading.current?.focus();
        }
    }, [state]);

    const refresh = () => {
        router.reload({
            only: ['purchase'],
            onStart: () => setRefreshing(true),
            onFinish: () => setRefreshing(false),
        });
    };

    // O token é o último segmento do caminho (`/compra/{token}`, com ou sem
    // prefixo de idioma): a página não o recebe em props, e o email nunca sai
    // do servidor.
    const saveAccount = () => {
        const token = window.location.pathname.split('/').filter(Boolean).pop();

        router.post(
            url(`/compra/${token}/conta`),
            {},
            {
                preserveScroll: true,
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
            },
        );
    };

    const withOrder = state === 'paid' || state === 'awaiting_payment';
    const number = purchase.number ?? '';

    const content = {
        confirming: {
            title: t('purchase.confirming_title'),
            text: gaveUp
                ? t('purchase.confirming_slow')
                : t('purchase.confirming_text'),
            tone: 'bg-muted text-muted-foreground',
            icon: gaveUp ? (
                <Clock aria-hidden="true" className="size-7" />
            ) : (
                <Spinner aria-hidden="true" className="size-7" />
            ),
        },
        paid: {
            title: t('purchase.paid_title', { number }),
            text: t('purchase.paid_text'),
            tone: 'bg-success-soft text-success-soft-foreground',
            icon: <CircleCheck aria-hidden="true" className="size-7" />,
        },
        awaiting_payment: {
            title: t('purchase.awaiting_title'),
            text: t('purchase.awaiting_text', { number }),
            tone: 'bg-warning-soft text-warning-soft-foreground',
            icon: <Clock aria-hidden="true" className="size-7" />,
        },
        expired: {
            title: t('purchase.expired_title'),
            text: t('purchase.expired_text'),
            tone: 'bg-muted text-muted-foreground',
            icon: <TimerOff aria-hidden="true" className="size-7" />,
        },
        closed: {
            title: t('purchase.closed_title'),
            text: t('purchase.closed_text'),
            tone: 'bg-muted text-muted-foreground',
            icon: <Ban aria-hidden="true" className="size-7" />,
        },
    }[state];

    return (
        <>
            <Head title={t('purchase.title')} />

            <div className="mx-auto flex w-full max-w-xl flex-col gap-8 px-4 py-10 md:py-16">
                <div
                    aria-live="polite"
                    className="flex flex-col items-center gap-4 text-center"
                >
                    <span
                        className={cn(
                            'flex size-14 items-center justify-center rounded-full',
                            content.tone,
                        )}
                    >
                        {content.icon}
                    </span>
                    <h1
                        ref={heading}
                        tabIndex={-1}
                        className="text-2xl font-semibold tracking-tight outline-none md:text-3xl"
                    >
                        {content.title}
                    </h1>
                    <p className="max-w-md text-muted-foreground">
                        {content.text}
                    </p>

                    {state === 'confirming' && gaveUp && (
                        <Button
                            type="button"
                            variant="outline"
                            disabled={refreshing}
                            onClick={refresh}
                        >
                            {refreshing && <Spinner aria-hidden="true" />}
                            {t('purchase.refresh')}
                        </Button>
                    )}
                    {state === 'expired' && (
                        <Button asChild>
                            <Link href={url('/carrinho')}>
                                {t('purchase.back_to_cart')}
                            </Link>
                        </Button>
                    )}
                    {state === 'closed' && (
                        <Button asChild>
                            <Link href={url('/contacto')}>
                                {t('purchase.contact')}
                            </Link>
                        </Button>
                    )}
                </div>

                {state !== 'expired' && (
                    <section
                        aria-label={t('cart.summary')}
                        className="flex flex-col gap-5 rounded-xl border border-border/60 bg-card p-5 text-card-foreground md:p-6"
                    >
                        <h2 className="text-lg font-semibold">
                            {t('cart.summary')}
                        </h2>

                        <ul className="-my-3 flex flex-col divide-y divide-border">
                            {purchase.lines.map((line, index) => (
                                <li key={index} className="flex gap-3 py-3">
                                    <div className="min-w-0 flex-1 text-sm">
                                        <p className="font-medium">
                                            {line.name}
                                        </p>
                                        {line.label && (
                                            <p className="text-muted-foreground">
                                                {line.label}
                                            </p>
                                        )}
                                        <p className="text-muted-foreground">
                                            {t('purchase.quantity', {
                                                count: line.qty,
                                            })}
                                        </p>
                                    </div>
                                    <p className="shrink-0 text-sm font-medium">
                                        {price(line.lineCents)}
                                    </p>
                                </li>
                            ))}
                        </ul>

                        <dl className="grid grid-cols-[1fr_auto] gap-x-4 gap-y-2 text-sm">
                            <dt className="text-muted-foreground">
                                {t('cart.subtotal')}
                            </dt>
                            <dd className="text-right">
                                {price(purchase.subtotalCents)}
                            </dd>

                            {purchase.discountCents > 0 && (
                                <>
                                    <dt className="text-muted-foreground">
                                        {t('cart.discount')}
                                    </dt>
                                    <dd className="text-right">
                                        −{price(purchase.discountCents)}
                                    </dd>
                                </>
                            )}

                            <dt className="text-muted-foreground">
                                {t('cart.shipping')}
                            </dt>
                            <dd className="text-right">
                                {purchase.shippingCents > 0
                                    ? price(purchase.shippingCents)
                                    : t('cart.free')}
                            </dd>

                            <dt className="border-t border-border pt-3 text-base font-semibold">
                                {t('cart.total')}
                            </dt>
                            <dd className="border-t border-border pt-3 text-right text-base font-semibold">
                                {price(purchase.totalCents)}
                            </dd>
                        </dl>

                        {withOrder && purchase.leadDays && (
                            <p className="border-t border-border pt-4 text-sm">
                                {t('purchase.lead', {
                                    from: purchase.leadDays[0],
                                    to: purchase.leadDays[1],
                                })}
                            </p>
                        )}
                    </section>
                )}

                {withOrder && purchase.canSaveAccount && (
                    <section
                        aria-labelledby="save-account-title"
                        className="flex flex-col gap-3 rounded-xl border border-border/60 bg-card p-5 text-card-foreground md:p-6"
                    >
                        <h2
                            id="save-account-title"
                            className="text-lg font-semibold"
                        >
                            {t('purchase.save_account_title')}
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            {t('purchase.save_account_text')}
                        </p>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={saving}
                            className="self-start"
                            onClick={saveAccount}
                        >
                            {saving ? (
                                <Spinner aria-hidden="true" />
                            ) : (
                                <Mail aria-hidden="true" />
                            )}
                            {t('purchase.save_account_button')}
                        </Button>
                    </section>
                )}

                <Link
                    href={url('/produtos')}
                    className="self-center text-sm text-muted-foreground underline underline-offset-4 hover:text-foreground"
                >
                    {t('purchase.back_to_shop')}
                </Link>
            </div>
        </>
    );
}
