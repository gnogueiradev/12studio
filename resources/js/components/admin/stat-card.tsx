import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

type Props = {
    label: string;
    /** Já formatado — o cartão não sabe de cêntimos nem de moeda. */
    value: string;
    /** Linha de contexto por baixo do número. */
    hint?: ReactNode;
    /** Tinge só o número. Para o cartão de stock baixo. */
    tone?: 'default' | 'warning';
};

/**
 * Métrica isolada do painel. `tabular-nums` porque os quatro cartões estão em
 * grelha: sem largura fixa de algarismo os números dançam entre visitas.
 */
export function StatCard({ label, value, hint, tone = 'default' }: Props) {
    return (
        <div className="rounded-xl border border-border/60 bg-card p-4">
            <p className="text-xs text-muted-foreground">{label}</p>
            <p
                className={cn(
                    'mt-2 text-3xl font-semibold tracking-tight tabular-nums',
                    tone === 'warning' && 'text-warning',
                )}
            >
                {value}
            </p>
            {hint && (
                <p className="mt-2 flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground">
                    {hint}
                </p>
            )}
        </div>
    );
}
