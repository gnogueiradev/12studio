import { Head, Link, router, useForm } from '@inertiajs/react';
import { LogOut } from 'lucide-react';
import type { ComponentProps } from 'react';
import InputError from '@/components/input-error';
import { OrderStatus } from '@/components/store/order-status';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useLocale, useLocalizedUrl, useT } from '@/lib/i18n';
import { usePrice } from '@/lib/money';
import type { AccountData, AccountOrderSummary } from '@/types/shop';

/** Um campo de texto com rótulo e erro, ligados por aria-describedby. */
function TextField({
    name,
    label,
    optional = false,
    error,
    ...input
}: {
    name: string;
    label: string;
    optional?: boolean;
    error?: string;
} & Omit<ComponentProps<typeof Input>, 'id' | 'name'>) {
    const t = useT();
    const id = `account-${name}`;

    return (
        <div className="grid content-start gap-1.5">
            <Label htmlFor={id}>
                {label}
                {optional && (
                    <span className="font-normal text-muted-foreground">
                        {' '}
                        ({t('form.optional')})
                    </span>
                )}
            </Label>
            <Input
                id={id}
                name={name}
                required={!optional}
                aria-invalid={error ? true : undefined}
                aria-describedby={error ? `${id}-error` : undefined}
                className="h-11"
                {...input}
            />
            <InputError id={`${id}-error`} message={error} />
        </div>
    );
}

/**
 * O /conta: as encomendas ligadas a esta conta, os dados que vêm preenchidos
 * na próxima compra (o email não se muda aqui) e o interruptor das ofertas,
 * que grava ao mudar.
 */
export default function AccountIndex({
    account,
    orders,
}: {
    account: AccountData;
    orders: AccountOrderSummary[];
}) {
    const t = useT();
    const url = useLocalizedUrl();
    const price = usePrice();
    const { intl } = useLocale();
    const form = useForm({
        name: account.name,
        phone: account.phone,
        nif: account.nif,
        line1: account.line1,
        line2: account.line2,
        postal_code: account.postalCode,
        city: account.city,
    });
    const { data, setData, errors } = form;

    return (
        <>
            <Head title={t('account.title')}>
                <meta name="robots" content="noindex" />
            </Head>
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-10 px-4 py-12">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {t('account.title')}
                    </h1>
                    <Button
                        variant="outline"
                        onClick={() => router.post(url('/conta/sair'))}
                    >
                        <LogOut aria-hidden="true" />
                        {t('account.logout')}
                    </Button>
                </div>

                <section aria-labelledby="account-orders">
                    <h2
                        id="account-orders"
                        className="mb-4 text-lg font-semibold"
                    >
                        {t('account.orders')}
                    </h2>
                    {orders.length === 0 ? (
                        <p className="text-muted-foreground">
                            {t('account.no_orders')}
                        </p>
                    ) : (
                        <ul className="divide-y divide-border rounded-lg border border-border">
                            {orders.map((order) => (
                                <li
                                    key={order.number}
                                    className="flex flex-wrap items-center gap-x-4 gap-y-2 p-4"
                                >
                                    <div className="min-w-0 flex-1">
                                        <p className="font-medium">
                                            {t('account.order_number', {
                                                number: order.number,
                                            })}
                                        </p>
                                        {order.date && (
                                            <p className="text-sm text-muted-foreground">
                                                {new Date(
                                                    order.date,
                                                ).toLocaleDateString(intl)}
                                            </p>
                                        )}
                                        {order.tracking?.number && (
                                            <p className="text-sm text-muted-foreground">
                                                {t('account.tracking')}:{' '}
                                                {order.tracking.number}
                                            </p>
                                        )}
                                    </div>
                                    <OrderStatus status={order.status} />
                                    <p className="font-medium tabular-nums">
                                        {price(order.totalCents)}
                                    </p>
                                    <Button asChild variant="outline" size="sm">
                                        <Link
                                            href={url(
                                                `/conta/encomendas/${order.number}`,
                                            )}
                                        >
                                            {t('account.view_order')}
                                        </Link>
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section aria-labelledby="account-details">
                    <h2
                        id="account-details"
                        className="mb-2 text-lg font-semibold"
                    >
                        {t('account.details_title')}
                    </h2>
                    <p className="mb-4 text-sm text-muted-foreground">
                        {t('account.details_text')}
                    </p>
                    <p className="mb-4 text-sm">
                        <span className="text-muted-foreground">
                            {t('form.email')}:
                        </span>{' '}
                        {account.email}
                    </p>
                    <form
                        className="grid gap-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.patch(url('/conta/dados'), {
                                preserveScroll: true,
                            });
                        }}
                    >
                        <TextField
                            name="name"
                            label={t('checkout.name')}
                            autoComplete="name"
                            maxLength={120}
                            value={data.name}
                            error={errors.name}
                            onChange={(event) =>
                                setData('name', event.target.value)
                            }
                        />
                        <div className="grid gap-4 sm:grid-cols-2">
                            <TextField
                                name="phone"
                                label={t('checkout.phone')}
                                type="tel"
                                inputMode="tel"
                                autoComplete="tel"
                                maxLength={30}
                                value={data.phone}
                                error={errors.phone}
                                onChange={(event) =>
                                    setData('phone', event.target.value)
                                }
                            />
                            <TextField
                                name="nif"
                                label={t('checkout.nif')}
                                optional
                                inputMode="numeric"
                                autoComplete="off"
                                maxLength={11}
                                value={data.nif}
                                error={errors.nif}
                                onChange={(event) =>
                                    setData('nif', event.target.value)
                                }
                            />
                        </div>
                        <TextField
                            name="line1"
                            label={t('checkout.line1')}
                            autoComplete="address-line1"
                            maxLength={120}
                            value={data.line1}
                            error={errors.line1}
                            onChange={(event) =>
                                setData('line1', event.target.value)
                            }
                        />
                        <TextField
                            name="line2"
                            label={t('checkout.line2')}
                            optional
                            autoComplete="address-line2"
                            maxLength={120}
                            value={data.line2}
                            error={errors.line2}
                            onChange={(event) =>
                                setData('line2', event.target.value)
                            }
                        />
                        <div className="grid gap-4 sm:grid-cols-[minmax(0,10rem)_minmax(0,1fr)]">
                            <TextField
                                name="postal_code"
                                label={t('checkout.postal_code')}
                                inputMode="numeric"
                                autoComplete="postal-code"
                                maxLength={8}
                                value={data.postal_code}
                                error={errors.postal_code}
                                onChange={(event) =>
                                    setData('postal_code', event.target.value)
                                }
                            />
                            <TextField
                                name="city"
                                label={t('checkout.city')}
                                autoComplete="address-level2"
                                maxLength={80}
                                value={data.city}
                                error={errors.city}
                                onChange={(event) =>
                                    setData('city', event.target.value)
                                }
                            />
                        </div>
                        <Button
                            type="submit"
                            disabled={form.processing}
                            className="self-start"
                        >
                            {form.processing && <Spinner />}
                            {t('account.details_save')}
                        </Button>
                    </form>
                </section>

                <section aria-labelledby="account-offers">
                    <h2
                        id="account-offers"
                        className="mb-4 text-lg font-semibold"
                    >
                        {t('account.offers_title')}
                    </h2>
                    <div className="flex items-start gap-3">
                        <Checkbox
                            id="account-offers-switch"
                            checked={account.offers}
                            onCheckedChange={(checked) =>
                                router.patch(
                                    url('/conta/ofertas'),
                                    { enabled: checked === true },
                                    { preserveScroll: true },
                                )
                            }
                            className="mt-0.5"
                        />
                        <Label
                            htmlFor="account-offers-switch"
                            className="font-normal"
                        >
                            {t('account.offers_label')}
                        </Label>
                    </div>
                </section>
            </div>
        </>
    );
}
