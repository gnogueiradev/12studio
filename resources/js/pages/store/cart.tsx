import { Head } from '@inertiajs/react';
import { useT } from '@/lib/i18n';
import type { CartData } from '@/types/shop';

/**
 * O carrinho. Por agora só o esqueleto, para a rota renderizar; a página
 * a sério (linhas, código, totais) vem a seguir.
 */
export default function CartPage({ cart }: { cart: CartData }) {
    const t = useT();

    return (
        <>
            <Head title={t('cart.title')}>
                <meta name="robots" content="noindex, nofollow" />
            </Head>

            <div className="mx-auto flex w-full max-w-3xl flex-col gap-4 px-4 py-8 md:py-12">
                <h1 className="text-2xl font-semibold tracking-tight md:text-3xl">
                    {t('cart.title')}
                </h1>
                <p className="text-sm text-muted-foreground">
                    {t('cart.count', { count: cart.count })}
                </p>
            </div>
        </>
    );
}
