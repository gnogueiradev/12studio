import type { ReactNode } from 'react';
import { StatCard } from '@/components/admin/stat-card';
import { formatCents, formatMicros, formatPercentBp } from '@/lib/money';
import { cn } from '@/lib/utils';
import type { PricingBreakdown as Breakdown } from '@/types/pricing';

type Props = {
    /** Null enquanto faltar peso ou tempo — sem tempo não há cálculo nenhum. */
    result: Breakdown | null;
    /** Total em minutos, para a linha "3h × 145 W × 0,1420 €/kWh". */
    printTimeMinutes: number;
    /** O €/h derivado da máquina, só para as linhas de detalhe. */
    hourlyCostMicros: number;
    printerName: string | null;
    /** True quando não há impressora ativa e os números vieram do config. */
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
 * O resultado de um cálculo de preço: os três números grandes, quem ganha o
 * quê, e o detalhe parcela a parcela.
 *
 * O detalhe mostra os valores em micro-euros com toda a precisão — 0,06177 €
 * de eletricidade, 2,10362 € de custo — e não os cêntimos arredondados. É essa
 * a razão de ele existir: quem abre isto quer perceber de onde veio o preço, e
 * um 0,06 € escondia precisamente a conta que se quer ver.
 */
export function PricingBreakdown({
    result,
    printTimeMinutes,
    hourlyCostMicros,
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
    const machineHint = `${formatDuration(printTimeMinutes)} × ${formatMicros(hourlyCostMicros)}/h${printerName ? ` · ${printerName}` : ''}`;

    return (
        <div className="flex flex-col gap-4">
            {usingFallbackRate && (
                <p className="rounded-lg border border-warning/40 bg-warning/10 px-3 py-2 text-sm text-warning">
                    Não há nenhuma impressora ativa. O cálculo está a usar uma
                    máquina por omissão do ficheiro de configuração (
                    {formatMicros(hourlyCostMicros)}/h) — cria uma impressora
                    para poderes mudar esses valores sem um deploy.
                </p>
            )}

            <div className="grid gap-3 sm:grid-cols-3">
                <StatCard
                    label={`Custo real de produção (${unit})`}
                    value={formatCents(result.productionCostCents)}
                    hint={`Material, energia, máquina, trabalho e risco de ${formatPercentBp(result.failureRateBp)}.`}
                />
                <StatCard
                    label="Preço para revenda"
                    value={formatCents(result.wholesalePriceCents)}
                    hint={
                        <>
                            Lucro {formatCents(result.wholesaleProfitCents)} ·
                            margem {formatPercentBp(result.wholesaleMarginBp)}
                        </>
                    }
                />
                <StatCard
                    label="Preço final recomendado"
                    value={formatCents(result.retailPriceCents)}
                    hint={
                        <>
                            Venda direta: lucro{' '}
                            {formatCents(result.directProfitCents)} · margem{' '}
                            {formatPercentBp(result.directMarginBp)}
                        </>
                    }
                />
            </div>

            <div className="grid gap-3 sm:grid-cols-2">
                <Party
                    title="O meu lucro"
                    rows={[
                        {
                            label: 'Venda a revendedor',
                            revenue: result.wholesalePriceCents,
                            profit: result.wholesaleProfitCents,
                            marginBp: result.wholesaleMarginBp,
                        },
                        {
                            label: 'Venda direta',
                            revenue: result.retailPriceCents,
                            profit: result.directProfitCents,
                            marginBp: result.directMarginBp,
                        },
                    ]}
                    cost={result.productionCostCents}
                />

                <div className="rounded-xl border border-border/60 bg-card p-4">
                    <h3 className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                        Revendedor
                    </h3>
                    <dl className="mt-3 grid grid-cols-2 gap-x-6 gap-y-2 text-sm">
                        <Metric
                            label="Compra"
                            value={formatCents(result.wholesalePriceCents)}
                        />
                        <Metric
                            label="Venda recomendada"
                            value={formatCents(result.retailPriceCents)}
                        />
                        <Metric
                            label="Lucro"
                            value={formatCents(result.resellerProfitCents)}
                        />
                        <Metric
                            label="Margem"
                            value={formatPercentBp(result.resellerMarginBp)}
                            hint={`markup ${formatPercentBp(result.resellerMarkupBp)}`}
                        />
                    </dl>
                </div>
            </div>

            {/*
             * Só aparece quando há mesmo comissões. A zero, este bloco era uma
             * linha de zeros a ocupar espaço em todos os cálculos de quem
             * vende só na própria loja.
             */}
            {result.channelFeeMicros > 0 && (
                <div className="rounded-xl border border-border/60 bg-card p-4">
                    <h3 className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                        Custos de venda
                    </h3>
                    <p className="mt-1 text-xs text-muted-foreground">
                        Fora do custo de produção: não custam nada produzir, só
                        entram no que sobra da venda direta.
                    </p>
                    <dl className="mt-3 grid grid-cols-2 gap-x-6 gap-y-2 text-sm sm:grid-cols-3">
                        <Metric
                            label="Comissão do canal"
                            value={formatCents(result.channelFeeCents)}
                        />
                        <Metric
                            label="Lucro líquido"
                            value={formatCents(result.netDirectProfitCents)}
                        />
                        <Metric
                            label="Margem líquida"
                            value={formatPercentBp(result.netDirectMarginBp)}
                        />
                    </dl>
                </div>
            )}

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
                        {formatCents(result.job.wholesalePriceCents)}
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

                <div className="flex flex-col gap-6 border-t border-border/60 p-4">
                    <section>
                        <h3 className="mb-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                            Custo de produção
                        </h3>
                        <table className="w-full text-sm">
                            <tbody className="[&_td]:py-1.5 [&_th]:py-1.5">
                                <Step
                                    label="Filamento"
                                    detail={`${isBatch ? 'da mesa, ' : ''}peso × preço por grama`}
                                    value={formatMicros(
                                        result.filamentCostMicros,
                                    )}
                                />
                                <Step
                                    label="Eletricidade"
                                    detail={`${formatDuration(printTimeMinutes)} de impressão à tarifa da casa`}
                                    value={formatMicros(
                                        result.electricityCostMicros,
                                    )}
                                />
                                <Step
                                    label="Depreciação da impressora"
                                    detail="a máquina a pagar-se a si própria nas peças que faz"
                                    value={formatMicros(
                                        result.depreciationCostMicros,
                                    )}
                                />
                                <Step
                                    label="Manutenção"
                                    detail="nozzle, hotend, correias, lubrificação"
                                    value={formatMicros(
                                        result.maintenanceCostMicros,
                                    )}
                                />
                                <Step
                                    label="Mão de obra"
                                    detail={
                                        isBatch
                                            ? `${result.laborMinutes} min da mesa: preparação + acabamento de cada peça`
                                            : `${result.laborMinutes} min de trabalho ativo`
                                    }
                                    value={formatMicros(result.laborCostMicros)}
                                />
                                <Step
                                    label="Embalagem"
                                    detail="saco, caixa, etiqueta"
                                    value={formatMicros(
                                        result.packagingCostMicros,
                                    )}
                                />
                                <Step
                                    label="Componentes"
                                    detail="ímanes, argolas, feltro, parafusos"
                                    value={formatMicros(
                                        result.componentsCostMicros,
                                    )}
                                />
                                <Step
                                    label="Subtotal"
                                    detail={`a soma das sete parcelas, ${unit}`}
                                    value={formatMicros(
                                        result.baseProductionCostMicros,
                                    )}
                                    emphasis
                                />
                                <Step
                                    label="Risco de falhas"
                                    detail={`o custo divide-se por (1 − ${formatPercentBp(result.failureRateBp)}), não se multiplica: a peça falhada também gastou tudo`}
                                    value={formatMicros(
                                        result.failureCostMicros,
                                    )}
                                />
                                <Step
                                    label="Custo real"
                                    detail={`o que custa mesmo produzir, ${unit}`}
                                    value={formatMicros(
                                        result.productionCostMicros,
                                    )}
                                    emphasis
                                />
                            </tbody>
                        </table>
                        <p className="mt-2 text-xs text-muted-foreground">
                            Máquina: {machineHint}.
                        </p>
                    </section>

                    <section>
                        <h3 className="mb-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                            Preços
                        </h3>
                        <table className="w-full text-sm">
                            <tbody className="[&_td]:py-1.5 [&_th]:py-1.5">
                                <Step
                                    label="Preço bruto de revenda"
                                    detail={`custo ÷ (1 − ${formatPercentBp(result.targetWholesaleMarginBp)})`}
                                    value={formatMicros(
                                        result.rawWholesalePriceMicros,
                                    )}
                                />
                                <Step
                                    label="Preço para revenda"
                                    detail="arredondado para cima aos 0,50 €"
                                    value={formatCents(
                                        result.wholesalePriceCents,
                                    )}
                                    emphasis
                                />
                                <Step
                                    label="Preço bruto ao cliente"
                                    detail={`revenda ÷ (1 − ${formatPercentBp(result.targetResellerMarginBp)})`}
                                    value={formatMicros(
                                        result.rawRetailPriceMicros,
                                    )}
                                />
                                <Step
                                    label="Preço final recomendado"
                                    detail="sempre para cima: 0,50 € até 20 €, 1 € até 50 €, 5 € acima disso"
                                    value={formatCents(result.retailPriceCents)}
                                    emphasis
                                />
                            </tbody>
                        </table>
                    </section>
                </div>
            </details>
        </div>
    );
}

/** Um bloco "receita − custo = lucro" por tipo de venda. */
function Party({
    title,
    rows,
    cost,
}: {
    title: string;
    rows: {
        label: string;
        revenue: number;
        profit: number;
        marginBp: number;
    }[];
    cost: number;
}) {
    return (
        <div className="rounded-xl border border-border/60 bg-card p-4">
            <h3 className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                {title}
            </h3>
            <div className="mt-3 flex flex-col gap-3">
                {rows.map((row) => (
                    <div key={row.label}>
                        <p className="text-xs text-muted-foreground">
                            {row.label}
                        </p>
                        <p className="text-sm tabular-nums">
                            {formatCents(row.revenue)} − {formatCents(cost)} ={' '}
                            <strong>{formatCents(row.profit)}</strong>
                            <span className="ml-1.5 text-xs text-muted-foreground">
                                {formatPercentBp(row.marginBp)}
                            </span>
                        </p>
                    </div>
                ))}
            </div>
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
