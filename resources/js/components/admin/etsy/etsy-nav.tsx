import { Link } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { index as connection } from '@/routes/admin/etsy';
import { index as listings } from '@/routes/admin/etsy/anuncios';
import { index as shipping } from '@/routes/admin/etsy/envios';
import { index as shop } from '@/routes/admin/etsy/loja';

type Section = 'anuncios' | 'envios' | 'loja';

const SECTIONS: { key: Section; label: string; href: () => { url: string } }[] =
    [
        { key: 'anuncios', label: 'Anúncios', href: listings },
        { key: 'envios', label: 'Envios', href: shipping },
        { key: 'loja', label: 'Loja', href: shop },
    ];

/**
 * As três divisões da Etsy no backoffice, lado a lado. A ligação em si (chaves
 * e OAuth) fica em Definições, com a password pedida outra vez — aqui só há
 * uma porta para lá.
 */
export function EtsyNav({ current }: { current: Section }) {
    return (
        <nav
            aria-label="Etsy"
            className="mt-4 flex flex-wrap items-center gap-x-5 gap-y-2 text-[13.5px]"
        >
            {SECTIONS.map((section) => (
                <Link
                    key={section.key}
                    href={section.href().url}
                    aria-current={section.key === current ? 'page' : undefined}
                    className={cn(
                        'border-b-2 pt-0.5 pb-1.5 transition-colors',
                        section.key === current
                            ? 'border-gold font-semibold text-foreground'
                            : 'border-transparent text-muted-foreground hover:text-foreground',
                    )}
                >
                    {section.label}
                </Link>
            ))}
            <Link
                href={connection().url}
                className="ml-auto text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
            >
                Ligação à loja
            </Link>
        </nav>
    );
}
