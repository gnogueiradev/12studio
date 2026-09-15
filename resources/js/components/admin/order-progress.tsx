import { label } from '@/lib/options';
import { cn } from '@/lib/utils';
import type { ProgressStep } from '@/types/order';
import { ORDER_STATUSES } from '@/types/order';

/**
 * Nomes curtos para a barra. Não são os de `ORDER_STATUSES`: ali "Pagamento
 * confirmado" distingue-se do "Pago" do pagamento na mesma linha; aqui cada
 * degrau tem 118 px e só precisa de dizer que etapa é.
 */
const STEP_LABELS: Record<string, string> = {
    pending_payment: 'Registada',
    paid: 'Pagamento',
    shipped: 'Enviada',
};

const BAR: Record<ProgressStep['state'], string> = {
    done: 'bg-success',
    current: 'bg-success',
    todo: 'bg-border',
    failed: 'bg-destructive',
};

type Props = {
    steps: ProgressStep[];
};

/**
 * A barra do topo do detalhe: por onde a encomenda já passou e o que lhe
 * falta. Os degraus que ela salta nem chegam do servidor.
 */
export function OrderProgress({ steps }: Props) {
    return (
        <ol className="flex flex-wrap gap-x-3.5 gap-y-2.5 rounded-xl border border-border/60 bg-card px-4 py-4">
            {steps.map((step) => (
                <li
                    key={step.status}
                    aria-current={step.state === 'current' ? 'step' : undefined}
                    className="flex min-w-0 flex-[1_1_118px] flex-col gap-2"
                >
                    <span
                        className={cn('h-[3px] rounded-full', BAR[step.state])}
                    />
                    <span className="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                        <span
                            className={cn(
                                'text-xs font-semibold text-pretty',
                                step.state === 'todo'
                                    ? 'text-muted-foreground'
                                    : step.state === 'failed'
                                      ? 'text-destructive'
                                      : 'text-foreground',
                            )}
                        >
                            {STEP_LABELS[step.status] ??
                                label(ORDER_STATUSES, step.status)}
                        </span>
                        {step.at && (
                            <span className="text-[11px] whitespace-nowrap text-muted-foreground tabular-nums">
                                {step.at}
                            </span>
                        )}
                    </span>
                </li>
            ))}
        </ol>
    );
}
