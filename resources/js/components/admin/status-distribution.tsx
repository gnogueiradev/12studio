import type { Tone } from '@/components/admin/status-badge';
import { TONES } from '@/components/admin/status-badge';
import { label } from '@/lib/options';
import type { StatusCount } from '@/types/dashboard';
import { ORDER_STATUSES } from '@/types/order';

/**
 * Preenchimento sólido por tom. Não reaproveita as classes do StatusBadge
 * porque essas são pares fundo-suave/texto: um `bg-*-soft` numa barra por
 * cima de uma calha `bg-muted` quase não se via.
 */
const FILL: Record<Tone, string> = {
    neutral: 'bg-muted-foreground',
    info: 'bg-info',
    warning: 'bg-warning',
    success: 'bg-success',
    danger: 'bg-destructive',
};

type Props = {
    counts: StatusCount[];
};

export function StatusDistribution({ counts }: Props) {
    // Escala pelo maior e não pelo total: com 14 encomendas repartidas por
    // cinco estados nenhuma barra passaria dos 30% e o gráfico ficava plano.
    const max = Math.max(...counts.map((entry) => entry.count), 1);

    return (
        <div className="flex flex-col gap-3">
            {counts.map((entry) => (
                <div key={entry.status} className="flex items-center gap-3">
                    <span className="w-40 flex-none text-sm text-muted-foreground">
                        {label(ORDER_STATUSES, entry.status)}
                    </span>
                    <span className="h-2 flex-1 overflow-hidden rounded-full bg-muted">
                        <span
                            className={`block h-full rounded-full ${FILL[TONES[entry.status] ?? 'neutral']}`}
                            style={{ width: `${(entry.count / max) * 100}%` }}
                        />
                    </span>
                    <span className="w-6 flex-none text-right text-sm font-semibold tabular-nums">
                        {entry.count}
                    </span>
                </div>
            ))}
        </div>
    );
}
