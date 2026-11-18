import { Check } from 'lucide-react';
import { useT } from '@/lib/i18n';
import { usePrice } from '@/lib/money';
import { cn } from '@/lib/utils';
import type { CartData } from '@/types/shop';

/**
 * As contas do carrinho (painel e página) e o que falta para os portes
 * grátis — a razão para juntar mais uma peça. Anunciadas com calma a quem usa
 * leitor de ecrã: mudar uma quantidade lê o total novo.
 */
export function CartTotals({
    cart,
    className,
}: {
    cart: CartData;
    className?: string;
}) {
    const t = useT();
    const price = usePrice();

    // Os portes são grátis quando o subtotal (antes do desconto) chega ao
    // limite: o limite é o subtotal mais o que falta.
    const threshold = cart.subtotalCents + cart.freeShippingLeftCents;
    const progress =
        threshold > 0 ? Math.min(1, cart.subtotalCents / threshold) : 1;

    return (
        <div
            aria-live="polite"
            className={cn('flex flex-col gap-4 text-sm', className)}
        >
            {cart.freeShippingLeftCents > 0 ? (
                <div className="flex flex-col gap-2">
                    <p>
                        {t('cart.shipping_left', {
                            amount: price(cart.freeShippingLeftCents),
                        })}
                    </p>
                    <div
                        aria-hidden="true"
                        className="h-1.5 overflow-hidden rounded-full bg-muted"
                    >
                        <div
                            className="h-full rounded-full bg-success transition-[width] duration-500"
                            style={{ width: `${progress * 100}%` }}
                        />
                    </div>
                </div>
            ) : (
                <p className="flex items-center gap-1.5 font-medium text-success-soft-foreground">
                    <Check aria-hidden="true" className="size-4" />
                    {t('cart.free_shipping')}
                </p>
            )}

            <dl className="grid grid-cols-[1fr_auto] gap-x-4 gap-y-2">
                <dt className="text-muted-foreground">{t('cart.subtotal')}</dt>
                <dd className="text-right">{price(cart.subtotalCents)}</dd>

                {cart.discountCents > 0 && (
                    <>
                        <dt className="text-muted-foreground">
                            {t('cart.discount')}
                        </dt>
                        <dd className="text-right">
                            −{price(cart.discountCents)}
                        </dd>
                    </>
                )}

                <dt className="text-muted-foreground">{t('cart.shipping')}</dt>
                <dd className="text-right">
                    {cart.shippingCents > 0
                        ? price(cart.shippingCents)
                        : t('cart.free')}
                </dd>

                <dt className="border-t border-border pt-3 text-base font-semibold">
                    {t('cart.total')}
                </dt>
                <dd className="border-t border-border pt-3 text-right text-base font-semibold">
                    {price(cart.totalCents)}
                </dd>
            </dl>
        </div>
    );
}
