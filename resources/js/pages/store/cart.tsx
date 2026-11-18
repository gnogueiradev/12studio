import { Head, Link, router } from '@inertiajs/react';
import { ShoppingBag, Tag, X } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { CartLines } from '@/components/store/cart-lines';
import {
    CartTotals,
    CheckoutButton,
    CheckoutReason,
} from '@/components/store/cart-totals';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useLocalizedUrl, useT } from '@/lib/i18n';
import { usePrice } from '@/lib/money';
import { cn } from '@/lib/utils';
import type { CartData } from '@/types/shop';

/**
 * O código de desconto: aplicar, ver em que estado ficou e retirar. A
 * mensagem de um código recusado vem do servidor, já traduzida.
 */
function PromoCode({ cart }: { cart: CartData }) {
    const t = useT();
    const url = useLocalizedUrl();
    const [code, setCode] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | undefined>();

    const options = {
        preserveScroll: true,
        onStart: () => setError(undefined),
        onFinish: () => setBusy(false),
        onError: (errors: Record<string, string>) =>
            setError(errors.promo_code ?? errors.cart),
    };

    const apply = (event: React.FormEvent) => {
        event.preventDefault();

        if (busy || code.trim() === '') {
            return;
        }

        setBusy(true);
        router.post(
            url('/carrinho/codigo'),
            { promo_code: code.trim() },
            { ...options, onSuccess: () => setCode('') },
        );
    };

    const remove = () => {
        setBusy(true);
        router.delete(url('/carrinho/codigo/remover'), options);
    };

    if (cart.promo) {
        const { promo } = cart;

        return (
            <div className="flex flex-col gap-2 text-sm">
                <div className="flex items-center justify-between gap-3">
                    <p className="flex min-w-0 items-center gap-2">
                        <Tag
                            aria-hidden="true"
                            className="size-4 shrink-0 text-muted-foreground"
                        />
                        <span className="truncate font-medium">
                            {promo.status === 'applied'
                                ? t('cart.code.applied', { code: promo.code })
                                : promo.code}
                        </span>
                    </p>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        disabled={busy}
                        onClick={remove}
                    >
                        {busy ? (
                            <Spinner aria-hidden="true" />
                        ) : (
                            <X aria-hidden="true" />
                        )}
                        {t('cart.code.remove')}
                    </Button>
                </div>
                {promo.status === 'pending_email' && (
                    <p className="text-muted-foreground">
                        {t('cart.code.pending')}
                    </p>
                )}
                {promo.status === 'refused' && promo.message && (
                    <p className="text-destructive">{promo.message}</p>
                )}
                <InputError message={error} />
            </div>
        );
    }

    return (
        <form onSubmit={apply} noValidate className="flex flex-col gap-1.5">
            <Label htmlFor="cart-promo-code">{t('cart.code.label')}</Label>
            <div className="flex gap-2">
                <Input
                    id="cart-promo-code"
                    autoComplete="off"
                    autoCapitalize="characters"
                    maxLength={40}
                    aria-invalid={error ? true : undefined}
                    value={code}
                    onChange={(event) => setCode(event.target.value)}
                    className="h-10"
                />
                <Button
                    type="submit"
                    variant="outline"
                    className="h-10"
                    disabled={busy}
                >
                    {busy && <Spinner aria-hidden="true" />}
                    {t('cart.code.apply')}
                </Button>
            </div>
            <InputError message={error} />
        </form>
    );
}

/**
 * O carrinho: as linhas à esquerda e o resumo (código, contas, "Finalizar
 * compra") à direita; no telemóvel, uma coluna, com o total e o botão numa
 * barra fixa em baixo. Cada mudança volta aqui e o `cart` das props já vem
 * refeito.
 */
export default function CartPage({ cart }: { cart: CartData }) {
    const t = useT();
    const url = useLocalizedUrl();
    const price = usePrice();

    const head = (
        <Head title={t('cart.title')}>
            <meta name="robots" content="noindex, nofollow" />
        </Head>
    );

    if (cart.lines.length === 0) {
        return (
            <>
                {head}
                <div className="mx-auto flex w-full max-w-3xl flex-col items-center gap-4 px-4 py-16 text-center md:py-24">
                    <ShoppingBag
                        aria-hidden="true"
                        className="size-10 text-muted-foreground"
                    />
                    <h1 className="text-2xl font-semibold tracking-tight md:text-3xl">
                        {t('cart.title')}
                    </h1>
                    <p className="text-muted-foreground">{t('cart.empty')}</p>
                    <Button asChild size="lg" className="mt-2 h-12 text-base">
                        <Link href={url('/produtos')}>{t('cart.browse')}</Link>
                    </Button>
                </div>
            </>
        );
    }

    // A barra do telemóvel cresce com a razão: o fundo da página também.
    const blocked = !cart.canCheckout;

    return (
        <>
            {head}

            <div
                className={cn(
                    'mx-auto w-full max-w-6xl px-4 pt-8 md:pt-12 md:pb-16',
                    blocked ? 'pb-56' : 'pb-28',
                )}
            >
                <div className="mb-6 flex items-baseline justify-between gap-4 md:mb-8">
                    <h1 className="text-2xl font-semibold tracking-tight md:text-3xl">
                        {t('cart.title')}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {t.choice('cart.pieces', cart.count)}
                    </p>
                </div>

                <div className="grid gap-8 md:grid-cols-[minmax(0,1fr)_340px] lg:grid-cols-[minmax(0,1fr)_380px] lg:gap-12">
                    <section aria-label={t('cart.title')}>
                        <CartLines lines={cart.lines} />
                    </section>

                    <aside
                        aria-label={t('cart.summary')}
                        className="flex flex-col gap-6 self-start rounded-xl border border-border/60 bg-card p-5 text-card-foreground md:sticky md:top-24 md:p-6"
                    >
                        <h2 className="text-lg font-semibold">
                            {t('cart.summary')}
                        </h2>
                        <PromoCode cart={cart} />
                        <CartTotals cart={cart} />
                        <div className="flex flex-col gap-3">
                            <CheckoutButton
                                cart={cart}
                                reasonId="cart-checkout-reason"
                                className="w-full"
                            />
                            <CheckoutReason
                                cart={cart}
                                id="cart-checkout-reason"
                            />
                        </div>
                    </aside>
                </div>
            </div>

            {/* Telemóvel: o total e o "Finalizar compra" sempre à mão, e,
                quando ainda não se pode, a razão ali mesmo. */}
            <div className="fixed inset-x-0 bottom-0 z-30 flex flex-col gap-2 border-t border-border/60 bg-background/95 px-4 py-3 backdrop-blur md:hidden">
                <CheckoutReason
                    cart={cart}
                    id="cart-checkout-reason-bar"
                    className="text-xs"
                />
                <div className="flex items-center gap-3">
                    <div className="min-w-0 flex-1">
                        <p className="text-xs text-muted-foreground">
                            {t('cart.total')}
                        </p>
                        <p className="font-semibold">
                            {price(cart.totalCents)}
                        </p>
                    </div>
                    <CheckoutButton
                        cart={cart}
                        reasonId="cart-checkout-reason-bar"
                    />
                </div>
            </div>
        </>
    );
}
