import { formatCents, formatPercentBp } from '@/lib/money';
import { cn } from '@/lib/utils';
import type { PriceEvaluation } from '@/types/pricing';
import {
    PRICE_EVALUATION_POSITION_LABELS as POSITIONS,
    PRICE_TIER_LABELS,
} from '@/types/pricing';

/**
 * O preço que estou a pensar usar, posto ao lado dos três níveis.
 *
 * Só informa. Abaixo do Mínimo há um aviso — mas pode haver boas razões para
 * vender lá (o mercado da Etsy está nos 4–5 €), e o que interessa nessa
 * decisão é ver o lucro por hora de impressora que fica.
 */
export function PriceEvaluationCard({
    evaluation,
    hasVat,
    showJob,
}: {
    evaluation: PriceEvaluation;
    hasVat: boolean;
    showJob: boolean;
}) {
    const position = evaluation.matchesTier
        ? `Igual ao ${PRICE_TIER_LABELS[evaluation.matchesTier]}`
        : POSITIONS[evaluation.position];

    return (
        <section
            aria-label="Preço avaliado"
            className={cn(
                'rounded-xl border bg-card p-4',
                evaluation.belowMinimum
                    ? 'border-warning/50'
                    : 'border-border/60',
            )}
        >
            <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <p className="text-xs text-muted-foreground">
                    O teu preço{hasVat && ' (IVA incl.)'}
                </p>
                <p
                    className={cn(
                        'text-xs font-medium',
                        evaluation.belowMinimum
                            ? 'text-warning'
                            : 'text-foreground',
                    )}
                >
                    {position}
                </p>
            </div>
            <p className="mt-1 text-2xl font-semibold tracking-tight tabular-nums">
                {formatCents(evaluation.priceCents)}
            </p>

            <dl className="mt-3 grid gap-x-6 gap-y-1 text-xs sm:grid-cols-2">
                {hasVat && (
                    <Row
                        label="Sem IVA"
                        value={formatCents(evaluation.priceExVatCents)}
                    />
                )}
                <Row label="Custo" value={formatCents(evaluation.costCents)} />
                <Row
                    label="Lucro"
                    value={formatCents(evaluation.profitCents)}
                    strong
                />
                {showJob && (
                    <Row
                        label="Lucro do trabalho"
                        value={formatCents(evaluation.jobProfitCents)}
                    />
                )}
                <Row
                    label="Margem"
                    value={formatPercentBp(evaluation.marginBp)}
                />
                <Row
                    label="Lucro por hora de impressora"
                    value={formatCents(evaluation.profitPerPrintHourCents)}
                    strong
                />
            </dl>

            {evaluation.warnings.map((warning) => (
                <p
                    key={warning.code}
                    className="mt-3 rounded-lg border border-warning/40 bg-warning/10 px-3 py-2 text-xs text-warning"
                >
                    {warning.message}
                </p>
            ))}
        </section>
    );
}

function Row({
    label,
    value,
    strong = false,
}: {
    label: string;
    value: string;
    strong?: boolean;
}) {
    return (
        <div className="flex items-baseline justify-between gap-3">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className={cn('tabular-nums', strong && 'font-medium')}>
                {value}
            </dd>
        </div>
    );
}
