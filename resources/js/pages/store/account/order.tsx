import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, RefreshCw } from 'lucide-react';
import { useState } from 'react';
import { OrderStatus } from '@/components/store/order-status';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useLocale, useLocalizedUrl, useT } from '@/lib/i18n';
import { usePrice } from '@/lib/money';
import type { AccountOrder } from '@/types/shop';

/**
 * Uma encomenda da conta, só para ler, e o "comprar outra vez": põe no
 * carrinho o que ainda se vende, ao preço de hoje, e segue para ele.
 */
export default function AccountOrderPage({ order }: { order: AccountOrder }) {
    const t = useT();
    const url = useLocalizedUrl();
    const price = usePrice();
    const { intl } = useLocale();
    const [busy, setBusy] = useState(false);

    return (
        <>
            <Head title={t('account.order_number', { number: order.number })}>
                <meta name="robots" content="noindex" />
            </Head>
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-8 px-4 py-12">
                <Link
                    href={url('/conta')}
                    className="inline-flex items-center gap-1.5 self-start text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft aria-hidden="true" className="size-4" />
                    {t('account.back')}
                </Link>

                <div className="flex flex-wrap items-center gap-3">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {t('account.order_number', { number: order.number })}
                    </h1>
                    <OrderStatus status={order.status} />
                </div>
                {order.date && (
                    <p className="-mt-6 text-sm text-muted-foreground">
                        {t('account.placed_on', {
                            date: new Date(order.date).toLocaleDateString(intl),
                        })}
                    </p>
                )}

                {order.tracking && (
                    <p className="text-sm">
                        <span className="text-muted-foreground">
                            {t('account.tracking')}:
                        </span>{' '}
                        {order.tracking.number}
                        {order.tracking.url && (
                            <>
                                {' '}
                                <a
                                    href={order.tracking.url}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="underline underline-offset-4"
                                >
                                    {t('account.track')}
                                </a>
                            </>
                        )}
                    </p>
                )}

                <ul className="divide-y divide-border rounded-lg border border-border">
                    {order.lines.map((line, index) => (
                        <li key={index} className="flex gap-3 p-4 text-sm">
                            <div className="min-w-0 flex-1">
                                <p className="font-medium">{line.name}</p>
                                {line.label && (
                                    <p className="text-muted-foreground">
                                        {line.label}
                                    </p>
                                )}
                                {line.personalization.map((field) => (
                                    <p
                                        key={field.label}
                                        className="break-words text-muted-foreground"
                                    >
                                        {field.label}:{' '}
                                        <span className="text-foreground">
                                            {field.value}
                                        </span>
                                    </p>
                                ))}
                                <p className="text-muted-foreground">
                                    {t('account.quantity', {
                                        count: line.qty,
                                    })}
                                </p>
                            </div>
                            <p className="shrink-0 font-medium tabular-nums">
                                {price(line.lineCents)}
                            </p>
                        </li>
                    ))}
                </ul>

                <dl className="ml-auto grid w-full max-w-xs grid-cols-[1fr_auto] gap-x-6 gap-y-1.5 text-sm">
                    <dt className="text-muted-foreground">
                        {t('cart.subtotal')}
                    </dt>
                    <dd className="text-right tabular-nums">
                        {price(order.subtotalCents)}
                    </dd>
                    {order.discountCents > 0 && (
                        <>
                            <dt className="text-muted-foreground">
                                {t('cart.discount')}
                            </dt>
                            <dd className="text-right tabular-nums">
                                −{price(order.discountCents)}
                            </dd>
                        </>
                    )}
                    <dt className="text-muted-foreground">
                        {t('cart.shipping')}
                    </dt>
                    <dd className="text-right tabular-nums">
                        {order.shippingCents === 0
                            ? t('cart.free')
                            : price(order.shippingCents)}
                    </dd>
                    <dt className="font-semibold">{t('cart.total')}</dt>
                    <dd className="text-right font-semibold tabular-nums">
                        {price(order.totalCents)}
                    </dd>
                </dl>

                {order.address && (
                    <section aria-labelledby="order-address">
                        <h2
                            id="order-address"
                            className="mb-2 text-lg font-semibold"
                        >
                            {t('account.address')}
                        </h2>
                        <address className="text-sm text-muted-foreground not-italic">
                            {order.address.map((line) => (
                                <span key={line} className="block">
                                    {line}
                                </span>
                            ))}
                        </address>
                    </section>
                )}

                {order.canReorder && (
                    <Button
                        className="self-start"
                        disabled={busy}
                        onClick={() =>
                            router.post(
                                url(
                                    `/conta/encomendas/${order.number}/comprar-outra-vez`,
                                ),
                                {},
                                {
                                    onStart: () => setBusy(true),
                                    onFinish: () => setBusy(false),
                                },
                            )
                        }
                    >
                        {busy ? <Spinner /> : <RefreshCw aria-hidden="true" />}
                        {t('account.reorder')}
                    </Button>
                )}
            </div>
        </>
    );
}
