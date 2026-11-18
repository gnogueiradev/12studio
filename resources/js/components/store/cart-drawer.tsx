import { Link } from '@inertiajs/react';
import { CheckCircle2 } from 'lucide-react';
import { useId } from 'react';
import { CartLines } from '@/components/store/cart-lines';
import {
    CartTotals,
    CheckoutButton,
    CheckoutReason,
} from '@/components/store/cart-totals';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { useLocalizedUrl, useT } from '@/lib/i18n';
import type { CartData } from '@/types/shop';

/**
 * O painel que abre depois de "Adicionar ao carrinho": o que lá está, o que
 * falta para os portes grátis e o caminho mais curto para pagar. Vive no
 * buy-box (e não no layout), para não abrir também em /carrinho, que recebe
 * o mesmo flash.
 */
export function CartDrawer({
    open,
    onOpenChange,
    cart,
    onCart,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    cart: CartData | null;
    onCart: (cart: CartData) => void;
}) {
    const t = useT();
    const url = useLocalizedUrl();
    const reasonId = useId();
    // Pode esvaziar-se aqui mesmo, removendo a última linha.
    const filled = cart !== null && cart.lines.length > 0;

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent className="store-theme flex w-full flex-col gap-0 bg-background text-foreground sm:max-w-md">
                <SheetHeader className="border-b border-border/60 p-6">
                    <SheetTitle className="flex items-center gap-2 text-xl">
                        {filled ? (
                            <>
                                <CheckCircle2
                                    aria-hidden="true"
                                    className="size-5 text-success"
                                />
                                {t('cart.added')}
                            </>
                        ) : (
                            t('cart.title')
                        )}
                    </SheetTitle>
                    <SheetDescription>
                        {t.choice('cart.pieces', cart?.count ?? 0)}
                    </SheetDescription>
                </SheetHeader>

                {cart && filled ? (
                    <>
                        <div className="flex-1 overflow-y-auto px-6">
                            <CartLines
                                lines={cart.lines}
                                compact
                                onCart={onCart}
                            />
                        </div>

                        <div className="flex flex-col gap-4 border-t border-border/60 p-6">
                            <CartTotals cart={cart} />
                            <div className="flex flex-col gap-2.5">
                                <CheckoutReason
                                    cart={cart}
                                    id={reasonId}
                                    className="text-xs"
                                />
                                <CheckoutButton
                                    cart={cart}
                                    reasonId={reasonId}
                                />
                                <Button
                                    asChild
                                    variant="outline"
                                    size="lg"
                                    className="h-12 text-base"
                                >
                                    <Link href={url('/carrinho')}>
                                        {t('cart.view')}
                                    </Link>
                                </Button>
                            </div>
                        </div>
                    </>
                ) : (
                    <p className="p-6 text-sm text-muted-foreground">
                        {t('cart.empty')}
                    </p>
                )}
            </SheetContent>
        </Sheet>
    );
}
