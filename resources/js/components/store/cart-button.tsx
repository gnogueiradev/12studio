import { Link } from '@inertiajs/react';
import { ShoppingBag } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useLocalizedUrl, useT } from '@/lib/i18n';

/**
 * O carrinho no cabeçalho: o ícone, as peças que lá estão e o caminho para
 * /carrinho. O número também está no nome ("Carrinho, 3 peças") — o leitor
 * de ecrã não lê o distintivo.
 */
export function CartButton({ count }: { count: number }) {
    const t = useT();
    const url = useLocalizedUrl();

    return (
        <Button asChild variant="ghost" size="icon" className="relative">
            <Link
                href={url('/carrinho')}
                aria-label={t.choice('cart.button', count)}
            >
                <ShoppingBag aria-hidden="true" />
                {count > 0 && (
                    <span
                        aria-hidden="true"
                        className="absolute -top-0.5 -right-0.5 flex h-5 min-w-5 items-center justify-center rounded-full bg-primary px-1 text-xs leading-none font-semibold text-primary-foreground tabular-nums"
                    >
                        {count > 99 ? '99+' : count}
                    </span>
                )}
            </Link>
        </Button>
    );
}
