import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, Lock, Tag, X } from 'lucide-react';
import type { ComponentProps, FormEvent } from 'react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { CartTotals } from '@/components/store/cart-totals';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useLocale, useLocalizedUrl, useT } from '@/lib/i18n';
import { usePrice } from '@/lib/money';
import { cn } from '@/lib/utils';
import type { CartData, CartLine, CheckoutFormData } from '@/types/shop';

type Field = Exclude<keyof CheckoutFormData, 'expected_total_cents'>;

/** A ordem dos campos no ecrã: o primeiro com erro recebe o foco. */
const FIELDS: Field[] = [
    'name',
    'email',
    'phone',
    'nif',
    'line1',
    'line2',
    'postal_code',
    'city',
    'terms',
];

/** Só para decidir quando vale a pena confirmar o código; quem valida é o servidor. */
const LOOKS_LIKE_EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

const fieldId = (name: Field) => `checkout-${name}`;

/** Um campo de texto com rótulo, ajuda e erro, ligados por aria-describedby. */
function TextField({
    name,
    label,
    help,
    optional = false,
    error,
    className,
    ...input
}: {
    name: Field;
    label: string;
    help?: string;
    optional?: boolean;
    error?: string;
    className?: string;
} & Omit<ComponentProps<typeof Input>, 'id' | 'name' | 'className'>) {
    const t = useT();
    const id = fieldId(name);
    const describedBy =
        [help && `${id}-help`, error && `${id}-error`]
            .filter(Boolean)
            .join(' ') || undefined;

    return (
        <div className={cn('grid content-start gap-1.5', className)}>
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
                aria-describedby={describedBy}
                className="h-11"
                {...input}
            />
            {help && (
                <p id={`${id}-help`} className="text-xs text-muted-foreground">
                    {help}
                </p>
            )}
            <InputError id={`${id}-error`} message={error} />
        </div>
    );
}

/** Uma linha do resumo: só para ler, as mudanças fazem-se no carrinho. */
function SummaryLine({ line }: { line: CartLine }) {
    const price = usePrice();

    return (
        <li className="flex gap-3 py-3">
            <span className="relative shrink-0">
                {line.image ? (
                    <img
                        src={line.image}
                        alt=""
                        loading="lazy"
                        className="size-14 rounded-md bg-muted object-cover"
                    />
                ) : (
                    <span className="block size-14 rounded-md bg-muted" />
                )}
                <span className="absolute -top-1.5 -right-1.5 flex size-5 items-center justify-center rounded-full bg-foreground text-[11px] font-semibold text-background tabular-nums">
                    {line.qty}
                </span>
            </span>
            <div className="min-w-0 flex-1 text-sm">
                <p className="font-medium">
                    <span className="sr-only">{line.qty} × </span>
                    {line.name}
                </p>
                {line.label && (
                    <p className="text-muted-foreground">{line.label}</p>
                )}
                {line.personalization.map((field) => (
                    <p
                        key={field.label}
                        className="break-words text-muted-foreground"
                    >
                        {field.label}:{' '}
                        <span className="text-foreground">{field.value}</span>
                    </p>
                ))}
            </div>
            <p className="shrink-0 text-sm font-medium">
                {price(line.lineCents)}
            </p>
        </li>
    );
}

/**
 * O /finalizar: os dados de quem compra à esquerda e o resumo à direita (no
 * telemóvel, um a seguir ao outro, com o total e o "Pagar" sempre à mão numa
 * barra em baixo). O "Pagar" envia o total que o cliente está a ver; se as
 * contas do servidor derem outro, nada se cobra e a página volta com o novo.
 *
 * Um código pessoal só se confirma com o email: quando o carrinho traz um à
 * espera e o email parece certo, a página pergunta ao servidor (sem gravar
 * nada) ao sair do campo, e o resumo passa a ser o que ele devolve.
 */
export default function CheckoutPage({ cart }: { cart: CartData }) {
    const t = useT();
    const url = useLocalizedUrl();
    const price = usePrice();
    const { current } = useLocale();

    // O carrinho feito conta com o email, e o email com que se fez.
    const [confirmed, setConfirmed] = useState<{
        email: string;
        cart: CartData;
    } | null>(null);
    const [checking, setChecking] = useState(false);
    const [removing, setRemoving] = useState(false);

    const { data, setData, post, processing, errors, transform } =
        useForm<CheckoutFormData>({
            name: '',
            email: '',
            phone: '',
            nif: '',
            line1: '',
            line2: '',
            postal_code: '',
            city: '',
            terms: false,
            expected_total_cents: cart.totalCents,
        });

    const email = data.email.trim();
    const waitsForEmail = cart.promo?.status === 'pending_email';
    // A confirmação só vale para o email com que foi feita.
    const summary =
        confirmed && confirmed.email === email ? confirmed.cart : cart;
    const busy = processing || checking;

    const confirmCode = () => {
        if (
            !waitsForEmail ||
            checking ||
            !LOOKS_LIKE_EMAIL.test(email) ||
            confirmed?.email === email
        ) {
            return;
        }

        router.post(
            url('/finalizar/codigo'),
            { email },
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setChecking(true),
                onFinish: () => setChecking(false),
                onSuccess: (page) => {
                    const flash = page.flash as { cart?: CartData };

                    if (flash.cart) {
                        setConfirmed({ email, cart: flash.cart });
                    }
                },
            },
        );
    };

    const removeCode = () => {
        router.delete(url('/carrinho/codigo/remover'), {
            preserveScroll: true,
            onStart: () => setRemoving(true),
            onFinish: () => setRemoving(false),
            onSuccess: () => setConfirmed(null),
        });
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (busy) {
            return;
        }

        transform((form) => ({
            ...form,
            expected_total_cents: summary.totalCents,
        }));
        post(url('/finalizar/pagar'), {
            preserveScroll: true,
            onError: (errors) => {
                const first = FIELDS.find((field) => errors[field]);

                if (first) {
                    document.getElementById(fieldId(first))?.focus();
                }

                // As contas mudaram: o resumo volta a ser o da página, e um
                // código pessoal confirma-se outra vez com o carrinho novo.
                if (errors.cart || errors.promo_code) {
                    setConfirmed(null);
                }
            },
        });
    };

    // Os erros que não são de um campo: o carrinho, o código, o Stripe.
    const otherErrors = errors as Record<string, string | undefined>;
    const payLabel = t('checkout.pay', { amount: price(summary.totalCents) });
    const payError =
        otherErrors.cart ??
        otherErrors.payment ??
        otherErrors.expected_total_cents;

    const payButton = (className?: string) => (
        <Button
            type="submit"
            size="lg"
            disabled={busy}
            aria-describedby="checkout-secure"
            className={cn('h-12 text-base', className)}
        >
            {processing ? (
                <>
                    <Spinner aria-hidden="true" />
                    {t('checkout.paying')}
                </>
            ) : (
                <>
                    <Lock aria-hidden="true" />
                    {payLabel}
                </>
            )}
        </Button>
    );

    return (
        <>
            <Head title={t('checkout.title')}>
                <meta name="robots" content="noindex, nofollow" />
            </Head>

            <form
                onSubmit={submit}
                noValidate
                className="mx-auto w-full max-w-6xl px-4 pt-8 pb-32 md:pt-12 md:pb-16"
            >
                <div className="mb-6 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-2 md:mb-8">
                    <h1 className="text-2xl font-semibold tracking-tight md:text-3xl">
                        {t('checkout.title')}
                    </h1>
                    <Link
                        href={url('/carrinho')}
                        className="inline-flex items-center gap-1.5 text-sm text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                    >
                        <ArrowLeft aria-hidden="true" className="size-4" />
                        {t('checkout.back')}
                    </Link>
                </div>

                <div className="grid gap-8 md:grid-cols-[minmax(0,1fr)_340px] lg:grid-cols-[minmax(0,1fr)_380px] lg:gap-12">
                    <div className="flex min-w-0 flex-col gap-8">
                        {current !== 'pt' && (
                            <p className="rounded-lg border border-border/60 bg-muted/50 px-4 py-3 text-sm">
                                {t('checkout.portugal_only')}
                            </p>
                        )}

                        <fieldset className="flex flex-col gap-4">
                            <legend className="mb-4 text-lg font-semibold">
                                {t('checkout.details')}
                            </legend>
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
                            <TextField
                                name="email"
                                label={t('checkout.email')}
                                help={t('checkout.email_help')}
                                type="email"
                                inputMode="email"
                                autoComplete="email"
                                autoCapitalize="none"
                                spellCheck={false}
                                maxLength={255}
                                value={data.email}
                                error={errors.email}
                                onChange={(event) =>
                                    setData('email', event.target.value)
                                }
                                onBlur={confirmCode}
                            />
                            <div className="grid gap-4 sm:grid-cols-2">
                                <TextField
                                    name="phone"
                                    label={t('checkout.phone')}
                                    help={t('checkout.phone_help')}
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
                                    help={t('checkout.nif_help')}
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
                        </fieldset>

                        <fieldset className="flex flex-col gap-4">
                            <legend className="mb-4 text-lg font-semibold">
                                {t('checkout.address')}
                            </legend>
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
                                    help={t('checkout.postal_code_help')}
                                    inputMode="numeric"
                                    autoComplete="postal-code"
                                    maxLength={8}
                                    value={data.postal_code}
                                    error={errors.postal_code}
                                    onChange={(event) =>
                                        setData(
                                            'postal_code',
                                            event.target.value,
                                        )
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
                            <div className="grid gap-1.5">
                                <p className="text-sm font-medium">
                                    {t('checkout.country')}
                                </p>
                                <p className="flex h-11 items-center rounded-md border border-border/60 bg-muted/50 px-3 text-sm">
                                    {t('checkout.portugal')}
                                </p>
                            </div>
                        </fieldset>
                    </div>

                    <aside
                        aria-label={t('cart.summary')}
                        className="flex flex-col gap-6 self-start rounded-xl border border-border/60 bg-card p-5 text-card-foreground md:sticky md:top-24 md:p-6"
                    >
                        <h2 className="text-lg font-semibold">
                            {t('cart.summary')}
                        </h2>

                        <ul className="-my-3 flex flex-col divide-y divide-border">
                            {summary.lines.map((line) => (
                                <SummaryLine key={line.id} line={line} />
                            ))}
                        </ul>

                        {summary.promo && (
                            <div className="flex flex-col gap-2 border-t border-border pt-4 text-sm">
                                <div className="flex items-center justify-between gap-3">
                                    <p className="flex min-w-0 items-center gap-2">
                                        <Tag
                                            aria-hidden="true"
                                            className="size-4 shrink-0 text-muted-foreground"
                                        />
                                        <span className="truncate font-medium">
                                            {summary.promo.status === 'applied'
                                                ? t('cart.code.applied', {
                                                      code: summary.promo.code,
                                                  })
                                                : summary.promo.code}
                                        </span>
                                    </p>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        disabled={removing || busy}
                                        onClick={removeCode}
                                    >
                                        {removing ? (
                                            <Spinner aria-hidden="true" />
                                        ) : (
                                            <X aria-hidden="true" />
                                        )}
                                        {t('cart.code.remove')}
                                    </Button>
                                </div>
                                {summary.promo.status === 'pending_email' && (
                                    <p
                                        className="text-muted-foreground"
                                        aria-live="polite"
                                    >
                                        {checking
                                            ? t('checkout.checking_code')
                                            : t('checkout.code_pending')}
                                    </p>
                                )}
                                {summary.promo.status === 'refused' &&
                                summary.promo.message ? (
                                    <p className="text-destructive">
                                        {summary.promo.message}
                                    </p>
                                ) : (
                                    <InputError
                                        message={otherErrors.promo_code}
                                    />
                                )}
                            </div>
                        )}

                        <CartTotals cart={summary} />

                        <div className="flex flex-col gap-1.5">
                            <div className="flex items-start gap-3">
                                <Checkbox
                                    id={fieldId('terms')}
                                    checked={data.terms}
                                    onCheckedChange={(checked) =>
                                        setData('terms', checked === true)
                                    }
                                    aria-invalid={
                                        errors.terms ? true : undefined
                                    }
                                    aria-describedby={
                                        errors.terms
                                            ? `${fieldId('terms')}-error`
                                            : undefined
                                    }
                                    className="mt-0.5"
                                />
                                <Label
                                    htmlFor={fieldId('terms')}
                                    className="block text-sm leading-snug font-normal"
                                >
                                    {t('checkout.terms_before')}
                                    <a
                                        href={url('/termos')}
                                        target="_blank"
                                        rel="noopener"
                                        className="font-medium underline underline-offset-4"
                                    >
                                        {t('checkout.terms_link')}
                                        <span className="sr-only">
                                            {' '}
                                            {t('checkout.new_tab')}
                                        </span>
                                    </a>
                                    {t('checkout.terms_middle')}
                                    <a
                                        href={url('/envios-e-devolucoes')}
                                        target="_blank"
                                        rel="noopener"
                                        className="font-medium underline underline-offset-4"
                                    >
                                        {t('checkout.shipping_link')}
                                        <span className="sr-only">
                                            {' '}
                                            {t('checkout.new_tab')}
                                        </span>
                                    </a>
                                    {t('checkout.terms_after')}
                                </Label>
                            </div>
                            <InputError
                                id={`${fieldId('terms')}-error`}
                                message={errors.terms}
                            />
                        </div>

                        <div className="flex flex-col gap-3">
                            <InputError message={payError} role="alert" />
                            {payButton('w-full')}
                            <p
                                id="checkout-secure"
                                className="text-center text-xs text-muted-foreground"
                            >
                                {t('checkout.secure')}
                            </p>
                        </div>
                    </aside>
                </div>

                {/* Telemóvel: o total e o "Pagar" sempre à mão. */}
                <div className="fixed inset-x-0 bottom-0 z-30 flex items-center gap-3 border-t border-border/60 bg-background/95 px-4 py-3 backdrop-blur md:hidden">
                    <div className="min-w-0 flex-1">
                        <p className="text-xs text-muted-foreground">
                            {t('cart.total')}
                        </p>
                        <p className="font-semibold">
                            {price(summary.totalCents)}
                        </p>
                    </div>
                    {payButton('shrink-0')}
                </div>
            </form>
        </>
    );
}
