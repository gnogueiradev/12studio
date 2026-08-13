import type { ReactNode } from 'react';
import { StatCard } from '@/components/admin/stat-card';
import {
    formatCents,
    formatMicros,
    formatMultiplierBp,
    formatPercentBp,
} from '@/lib/money';
import { cn } from '@/lib/utils';
import type { PricingBreakdown as Breakdown } from '@/types/pricing';

type Props = {
    /** Null enquanto faltar peso ou tempo — sem tempo não há cálculo nenhum. */
    result: Breakdown | null;
    /** Total em minutos, para a linha "1h 30m × 0,50 €/h". */
    printTimeMinutes: number;
    hourlyRateCents: number;
    printerName: string | null;
    /** True quando não há impressora ativa e a taxa veio do ficheiro de config. */
    usingFallbackRate: boolean;
    /** O botão "Aplicar preços", quando quem chama tem onde os aplicar. */
    action?: ReactNode;
    /** O que dizer quando não há resultado. */
    emptyHint?: string;
};

/** 90 → "1h 30m". Zero minutos é o estado vazio, tratado antes de chegar aqui. */
function formatDuration(minutes: number): string {
    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;

    if (hours === 0) {
        return `${rest}m`;
    }

    return rest === 0 ? `${hours}h` : `${hours}h ${rest}m`;
}

/**
 * O resultado de um cálculo de preço: os três números grandes, as margens, e o
 * detalhe passo a passo.
 *
 * O detalhe mostra os valores em micro-euros com toda a precisão — 0,10352 € de
 * reserva, 1,64752 € de custo — e não os cêntimos arredondados. É essa a razão
 * de ele existir: quem abre isto quer perceber de onde veio o preço, e um
 * 0,10 € escondia precisamente a conta que se quer ver.
 */
export function PricingBreakdown({
    result,
    printTimeMinutes,
    hourlyRateCents,
    printerName,
    usingFallbackRate,
    action,
    emptyHint = 'Preenche o peso e o tempo de impressão para ver o preço.',
}: Props) {
    if (result === null) {
        return (
            <div className="rounded-xl border border-dashed border-border/60 p-6 text-center text-sm text-muted-foreground">
                {emptyHint}
            </div>
        );
    }

    const isBatch = result.mode === 'batch';
    const unit = isBatch ? 'por unidade' : 'por peça';

    return (
        <div className="flex flex-col gap-4">
            {usingFallbackRate && (
                <p className="rounded-lg border border-warning/40 bg-warning/10 px-3 py-2 text-sm text-warning">
                    Não há nenhuma impressora ativa. O cálculo está a usar{' '}
                    {formatCents(hourlyRateCents)}/h do ficheiro de configuração
                    — cria uma impressora para poderes mudar esse valor sem um
                    deploy.
                </p>
            )}

            <div className="grid gap-3 sm:grid-cols-3">
                <StatCard
                    label={`Custo real estimado (${unit})`}
                    value={formatCents(result.productionCostCents)}
                    hint={`Material, máquina, manuseamento, risco${result.extraCostMicros > 0 ? ' e extras' : ''}.`}
                />
                <StatCard
                    label="Preço para revenda"
                    value={formatCents(result.resalePriceCents)}
                    hint={
                        <>
                            {formatMultiplierBp(result.resaleMultiplierBp)}{' '}
                            sobre o custo · lucro{' '}
                            {formatCents(result.producerProfitCents)}
                        </>
                    }
                />
                <StatCard
                    label="Preço recomendado ao cliente"
                    value={formatCents(result.retailPriceCents)}
                    hint={
                        result.retailBumped ? (
                            <span className="text-warning">
                                Subido de{' '}
                                {formatMicros(result.rawRetailPriceMicros)} para
                                o revendedor não ficar abaixo da margem mínima.
                            </span>
                        ) : (
                            <>
                                Lucro do revendedor{' '}
                                {formatCents(result.resellerProfitCents)}
                            </>
                        )
                    }
                />
            </div>

            <dl className="grid grid-cols-2 gap-x-6 gap-y-2 rounded-xl border border-border/60 bg-card p-4 text-sm sm:grid-cols-4">
                <Metric
                    label="Lucro produtor"
                    value={formatCents(result.producerProfitCents)}
                />
                <Metric
                    label="Margem produtor"
                    value={formatPercentBp(result.producerMarginBp)}
                />
                <Metric
                    label="Lucro revendedor"
                    value={formatCents(result.resellerProfitCents)}
                />
                <Metric
                    label="Margem revendedor"
                    value={formatPercentBp(result.resellerMarginBp)}
                    hint={`markup ${formatPercentBp(result.resellerMarkupBp)}`}
                />
            </dl>

            {result.quantity > 1 && (
                <p className="text-sm text-muted-foreground">
                    {isBatch
                        ? `A mesa inteira (${result.quantity} unidades)`
                        : `${result.quantity} unidades`}
                    : custo{' '}
                    <strong className="text-foreground tabular-nums">
                        {formatCents(result.job.productionCostCents)}
                    </strong>
                    , revenda{' '}
                    <strong className="text-foreground tabular-nums">
                        {formatCents(result.job.resalePriceCents)}
                    </strong>
                    , cliente{' '}
                    <strong className="text-foreground tabular-nums">
                        {formatCents(result.job.retailPriceCents)}
                    </strong>
                    .
                </p>
            )}

            {action}

            {/*
             * <details> nativo e não um Collapsible: abre e fecha com o teclado
             * sem uma linha de JavaScript, e o estado sobrevive a um recarregar
             * parcial do Inertia porque vive no DOM e não no React.
             */}
            <details className="group rounded-xl border border-border/60 bg-card">
                <summary className="cursor-pointer list-none p-4 text-sm font-medium select-none">
                    <span className="group-open:hidden">
                        Ver cálculo detalhado
                    </span>
                    <span className="hidden group-open:inline">
                        Esconder cálculo
                    </span>
                </summary>

                <div className="border-t border-border/60 p-4">
                    <table className="w-full text-sm">
                        <tbody className="[&_td]:py-1.5 [&_th]:py-1.5">
                            <Step
                                label="Filamento"
                                detail={`${result.quantity > 1 && isBatch ? 'da mesa, ' : ''}peso × preço por grama`}
                                value={formatMicros(result.filamentCostMicros)}
                            />
                            <Step
                                label="Máquina"
                                detail={`${formatDuration(printTimeMinutes)} × ${formatCents(hourlyRateCents)}/h${printerName ? ` · ${printerName}` : ''}`}
                                value={formatMicros(result.machineCostMicros)}
                            />
                            <Step
                                label="Manuseamento"
                                detail={
                                    isBatch
                                        ? 'montagem da mesa + trabalho de cada peça'
                                        : 'preparar, tirar da mesa, limpar, embalar'
                                }
                                value={formatMicros(result.handlingCostMicros)}
                            />
                            <Step
                                label="Reserva para falhas"
                                detail="sobre filamento + máquina; os extras não pagam risco"
                                value={formatMicros(
                                    result.failureReserveMicros,
                                )}
                            />
                            {result.extraCostMicros > 0 && (
                                <Step
                                    label="Custos adicionais"
                                    detail="ímanes, feltro, caixa, componentes"
                                    value={formatMicros(result.extraCostMicros)}
                                />
                            )}
                            <Step
                                label="Custo real"
                                detail={`a soma, ${unit}`}
                                value={formatMicros(
                                    result.productionCostMicros,
                                )}
                                emphasis
                            />

                            <Step
                                label="Preço bruto de revenda"
                                detail={`custo × ${formatMultiplierBp(result.resaleMultiplierBp)}`}
                                value={formatMicros(
                                    result.rawResalePriceMicros,
                                )}
                            />
                            <Step
                                label="Preço para revenda"
                                detail="arredondado para cima aos 0,50 €"
                                value={formatCents(result.resalePriceCents)}
                                emphasis
                            />

                            <Step
                                label="Preço bruto ao cliente"
                                detail="revenda × multiplicador do cliente"
                                value={formatMicros(
                                    result.rawRetailPriceMicros,
                                )}
                            />
                            {result.retailBumped && (
                                <Step
                                    label="Mínimo do revendedor"
                                    detail="o arredondamento tinha descido abaixo disto"
                                    value={formatMicros(
                                        result.minimumRetailPriceMicros,
                                    )}
                                />
                            )}
                            <Step
                                label="Preço recomendado"
                                detail={
                                    result.retailBumped
                                        ? 'subido ao próximo preço comercial válido'
                                        : 'arredondamento comercial'
                                }
                                value={formatCents(result.retailPriceCents)}
                                emphasis
                            />
                        </tbody>
                    </table>
                </div>
            </details>
        </div>
    );
}

function Metric({
    label,
    value,
    hint,
}: {
    label: string;
    value: string;
    hint?: string;
}) {
    return (
        <div>
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="font-medium tabular-nums">
                {value}
                {hint && (
                    <span className="ml-1.5 text-xs font-normal text-muted-foreground">
                        {hint}
                    </span>
                )}
            </dd>
        </div>
    );
}

function Step({
    label,
    detail,
    value,
    emphasis = false,
}: {
    label: string;
    detail: string;
    value: string;
    emphasis?: boolean;
}) {
    return (
        <tr className="border-b border-border/40 last:border-0">
            <th
                scope="row"
                className={cn(
                    'text-left',
                    emphasis ? 'font-semibold' : 'font-normal',
                )}
            >
                {label}
                <span className="mt-0.5 block text-xs font-normal text-muted-foreground">
                    {detail}
                </span>
            </th>
            <td
                className={cn(
                    'text-right align-top tabular-nums',
                    emphasis && 'font-semibold',
                )}
            >
                {value}
            </td>
        </tr>
    );
}
