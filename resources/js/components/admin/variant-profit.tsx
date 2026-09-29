import { formatCents, formatPercentBp } from '@/lib/money';
import { cn } from '@/lib/utils';

type Props = {
    /** O custo real por peça, do mesmo motor que o painel de custo. Null = por calcular. */
    productionCostCents: number | null;
    normalCents: number;
    /** Null sem promoção. */
    saleCents: number | null;
    /** Null sem preço de revenda. */
    wholesaleCents: number | null;
};

/**
 * Quanto sobra com os preços que ESTÃO nos campos — e não com os sugeridos,
 * que o painel de custo já mostra. É a pergunta de quem pôs 5 € à mão: isto
 * dá lucro?
 *
 * Sem custo não há conta: o custo só existe com gramagem E tempo de
 * impressão, porque sem tempo não se sabe quanto a máquina gastou.
 */
export function VariantProfit({
    productionCostCents,
    normalCents,
    saleCents,
    wholesaleCents,
}: Props) {
    if (productionCostCents === null) {
        return (
            <p className="-mt-2 text-xs text-muted-foreground">
                Preenche a gramagem e o tempo de impressão para veres quanto
                ganhas com estes preços.
            </p>
        );
    }

    const rows = [
        { label: 'Preço normal', price: normalCents },
        saleCents !== null && { label: 'Em promoção', price: saleCents },
        wholesaleCents !== null && { label: 'Revenda', price: wholesaleCents },
    ].filter((row): row is { label: string; price: number } => row !== false);

    return (
        <div className="rounded-xl border border-border/60 bg-card p-4">
            <h3 className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                O teu lucro com estes preços
            </h3>
            <p className="mt-1 text-xs text-muted-foreground">
                Custo real de produção:{' '}
                <span className="tabular-nums">
                    {formatCents(productionCostCents)}
                </span>{' '}
                por peça.
            </p>
            <dl className="mt-3 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-3">
                {rows.map((row) => {
                    const profit = row.price - productionCostCents;
                    // Margem sobre a VENDA, como em PricingResult::marginBp.
                    const marginBp =
                        row.price === 0
                            ? 0
                            : Math.round((profit * 10_000) / row.price);

                    return (
                        <div key={row.label}>
                            <dt className="text-xs text-muted-foreground">
                                {row.label} · {formatCents(row.price)}
                            </dt>
                            <dd
                                className={cn(
                                    'font-medium tabular-nums',
                                    profit < 0
                                        ? 'text-destructive'
                                        : 'text-success',
                                )}
                            >
                                {profit < 0
                                    ? `Prejuízo de ${formatCents(-profit)}`
                                    : formatCents(profit)}
                                <span className="ml-1.5 text-xs font-normal text-muted-foreground">
                                    margem {formatPercentBp(marginBp)}
                                </span>
                            </dd>
                        </div>
                    );
                })}
            </dl>
        </div>
    );
}
