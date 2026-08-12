import { formatCents } from '@/lib/money';
import { cn } from '@/lib/utils';
import type { WeekBucket } from '@/types/dashboard';

type Props = {
    weeks: WeekBucket[];
};

/** Fatia mínima visível: sem isto uma semana a zero desaparecia do eixo. */
const FLOOR_PERCENT = 2;

/**
 * Doze semanas de receita em barras de CSS.
 *
 * Sem biblioteca de gráficos de propósito: o projeto não tem nenhuma
 * instalada, e uma série única sem eixos, tooltips ou zoom não justifica
 * trazer o recharts (e o runtime dele) para o bundle. Cada barra leva o valor
 * no `title` e no `aria-label`, que é o que um tooltip daria aqui.
 */
export function WeeklySalesChart({ weeks }: Props) {
    const max = Math.max(...weeks.map((week) => week.cents), 1);

    // Um rótulo por mês, não por semana — doze rótulos não cabiam.
    const months = weeks.reduce<string[]>(
        (acc, week) => (acc.at(-1) === week.month ? acc : [...acc, week.month]),
        [],
    );

    return (
        <>
            <div className="flex h-32 items-end gap-2">
                {weeks.map((week) => (
                    <span
                        key={week.weekLabel}
                        title={`${week.weekLabel} · ${formatCents(week.cents)}`}
                        aria-label={`Semana de ${week.weekLabel}: ${formatCents(week.cents)}`}
                        className={cn(
                            'flex-1 rounded-t-sm',
                            week.current ? 'bg-gold' : 'bg-secondary-hover',
                        )}
                        style={{
                            height: `${Math.max(FLOOR_PERCENT, (week.cents / max) * 100)}%`,
                        }}
                    />
                ))}
            </div>
            <div className="mt-2 flex justify-between text-xs text-muted-foreground">
                {months.map((month) => (
                    <span key={month}>{month}</span>
                ))}
            </div>
        </>
    );
}
