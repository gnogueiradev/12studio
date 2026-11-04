import { Check, Copy } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useClipboard } from '@/hooks/use-clipboard';
import { formatCents, formatPercentBp } from '@/lib/money';
import { cn } from '@/lib/utils';
import type {
    PriceTier,
    PriceTierName,
    PricingBreakdown,
} from '@/types/pricing';
import {
    PRICE_TIER_LABELS as LABELS,
    PRICE_TIER_NAMES,
    PRICE_TIER_RULE_LABELS as RULES,
} from '@/types/pricing';

/** 1449 → "14,49": o que se cola num campo de preço do backoffice. */
function toInput(cents: number): string {
    return (cents / 100).toFixed(2).replace('.', ',');
}

/**
 * Os três preços sugeridos lado a lado, o Recomendado em destaque.
 *
 * Cada cartão diz qual das regras o decidiu e quanto deixa por hora de
 * impressora — é esse o número que a margem sobre o custo escondia nas peças
 * leves e demoradas.
 */
export function PriceTierCards({ result }: { result: PricingBreakdown }) {
    const [copied, copy] = useClipboard();
    const hasVat = result.vatRateBp > 0;
    const showJob = result.quantity > 1;

    return (
        // Pela largura da COLUNA e não do ecrã: na calculadora os cartões
        // vivem ao lado do formulário, e a 1024 px três lado a lado ficavam
        // com 107 px cada. Abaixo de ~42rem empilham.
        <div className="@container">
            <div className="grid gap-3 @2xl:grid-cols-3">
                {PRICE_TIER_NAMES.map((name) => (
                    <TierCard
                        key={name}
                        name={name}
                        tier={result.tiers[name]}
                        hasVat={hasVat}
                        showJob={showJob}
                        // Os três preços são sempre diferentes: o texto copiado
                        // chega para saber qual cartão mostra o visto.
                        copied={
                            copied === toInput(result.tiers[name].priceCents)
                        }
                        onCopy={() =>
                            void copy(toInput(result.tiers[name].priceCents))
                        }
                    />
                ))}
            </div>
        </div>
    );
}

/** Uma linha "rótulo … valor": nunca se colam, por mais estreito que fique. */
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

function TierCard({
    name,
    tier,
    hasVat,
    showJob,
    copied,
    onCopy,
}: {
    name: PriceTierName;
    tier: PriceTier;
    hasVat: boolean;
    showJob: boolean;
    copied: boolean;
    onCopy: () => void;
}) {
    const featured = name === 'recommended';

    return (
        <div
            className={cn(
                'flex flex-col rounded-xl border bg-card p-4',
                featured
                    ? 'border-primary ring-1 ring-primary/40'
                    : 'border-border/60',
            )}
        >
            <div className="flex items-center justify-between gap-2">
                <p
                    className={cn(
                        'text-xs',
                        featured
                            ? 'font-semibold text-foreground'
                            : 'text-muted-foreground',
                    )}
                >
                    {LABELS[name]}
                    {hasVat && ' (IVA incl.)'}
                </p>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="size-7"
                    onClick={onCopy}
                    aria-label={`Copiar o preço ${LABELS[name]}`}
                >
                    {copied ? <Check /> : <Copy />}
                </Button>
            </div>
            <p className="mt-1 text-3xl font-semibold tracking-tight tabular-nums">
                {formatCents(tier.priceCents)}
            </p>
            <dl className="mt-3 flex flex-col gap-1 text-xs">
                {hasVat && (
                    <>
                        <Row
                            label="Sem IVA"
                            value={formatCents(tier.priceExVatCents)}
                        />
                        <Row label="IVA" value={formatCents(tier.vatCents)} />
                    </>
                )}
                <Row
                    label="Lucro"
                    value={formatCents(tier.profitCents)}
                    strong
                />
                {showJob && (
                    <Row
                        label="Lucro do trabalho"
                        value={formatCents(tier.jobProfitCents)}
                    />
                )}
                <Row label="Margem" value={formatPercentBp(tier.marginBp)} />
                <Row
                    label="Lucro por hora de impressora"
                    value={formatCents(tier.profitPerPrintHourCents)}
                    strong
                />
                <Row
                    label="Com o meu trabalho, por hora"
                    value={formatCents(tier.earningsPerPrintHourCents)}
                />
                {tier.designCostCents > 0 && (
                    <Row
                        label="Design"
                        value={formatCents(tier.designCostCents)}
                    />
                )}
            </dl>
            <p className="mt-3 text-xs text-muted-foreground">
                Decidido {RULES[tier.rule]}
                {tier.raisedForOrder && ', subido para ficar acima do anterior'}
                .
            </p>
        </div>
    );
}
