import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

type Props = {
    title: string;
    /** Canto superior direito: uma contagem, um link "Ver todas". */
    aside?: ReactNode;
    children: ReactNode;
    className?: string;
};

/**
 * Cartão do painel. A casca repete as classes da AdminTable de propósito
 * (`rounded-xl border border-border/60`) — no painel os dois ficam lado a
 * lado, e uma diferença de meio tom na borda dava logo nas vistas.
 *
 * Não usa o `<Card>` do shadcn: o `py-6 gap-6` de origem é demasiado folgado
 * para blocos desta densidade, e sobrepor-lhe classes ficava mais confuso do
 * que a casca direta.
 */
export function Panel({ title, aside, children, className }: Props) {
    return (
        <section
            className={cn(
                'rounded-xl border border-border/60 bg-card p-4',
                className,
            )}
        >
            <div className="mb-4 flex items-baseline justify-between gap-3">
                <h2 className="text-sm font-semibold">{title}</h2>
                {aside && <div className="text-xs">{aside}</div>}
            </div>
            {children}
        </section>
    );
}
