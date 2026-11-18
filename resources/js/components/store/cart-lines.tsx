import { Link, router } from '@inertiajs/react';
import { Minus, Plus } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useLocalizedUrl, useT } from '@/lib/i18n';
import { usePrice } from '@/lib/money';
import { cn } from '@/lib/utils';
import type { CartData, CartLine } from '@/types/shop';

/**
 * O limite geral de peças por linha (config('shop.cart.max_qty')). O
 * servidor é quem manda; isto só desenha o teto do seletor quando a
 * variante não tem outro mais baixo (o stock).
 */
export const MAX_QTY = 20;

/**
 * − número +. O mínimo é 1 (tirar a linha é o "Remover"); o máximo, o teto
 * que vier. Botões a sério, com nome, e o número anunciado quando muda.
 */
export function QuantityStepper({
    value,
    max,
    onChange,
    disabled = false,
    label,
    className,
}: {
    value: number;
    max: number;
    onChange: (value: number) => void;
    disabled?: boolean;
    /** O nome do grupo para o leitor de ecrã ("Quantidade de Vaso Ondas"). */
    label: string;
    className?: string;
}) {
    const t = useT();

    return (
        <div
            role="group"
            aria-label={label}
            className={cn(
                'inline-flex items-center rounded-md border border-border bg-background',
                className,
            )}
        >
            <Button
                type="button"
                variant="ghost"
                className="h-full w-10 rounded-r-none px-0"
                disabled={disabled || value <= 1}
                onClick={() => onChange(value - 1)}
                aria-label={t('cart.decrease')}
            >
                <Minus aria-hidden="true" />
            </Button>
            <output
                aria-live="polite"
                className="min-w-8 text-center text-sm font-medium tabular-nums"
            >
                {value}
            </output>
            <Button
                type="button"
                variant="ghost"
                className="h-full w-10 rounded-l-none px-0"
                disabled={disabled || value >= max}
                onClick={() => onChange(value + 1)}
                aria-label={t('cart.increase')}
            >
                <Plus aria-hidden="true" />
            </Button>
        </div>
    );
}

/** A razão, em palavras, de uma linha que já não se pode comprar assim. */
function problemText(
    line: CartLine,
    t: ReturnType<typeof useT>,
): string | null {
    switch (line.problem) {
        case 'over_stock':
            return t('cart.problem.over_stock', { count: line.maxQty ?? 0 });
        case 'sold_out':
        case 'not_for_sale':
        case 'custom':
            return t(`cart.problem.${line.problem}`);
        default:
            return null;
    }
}

function CartLineRow({
    line,
    compact,
    onCart,
}: {
    line: CartLine;
    compact: boolean;
    onCart?: (cart: CartData) => void;
}) {
    const t = useT();
    const price = usePrice();
    const url = useLocalizedUrl();
    // Um pedido de cada vez por linha: os controlos ficam desligados até
    // à resposta (um duplo clique não manda duas quantidades).
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const options = {
        preserveScroll: true,
        onStart: () => {
            setBusy(true);
            setError(null);
        },
        onFinish: () => setBusy(false),
        onError: (errors: Record<string, string>) =>
            setError(errors.qty ?? errors.cart ?? null),
        onSuccess: (page: { flash: Record<string, unknown> }) => {
            const flash = page.flash as { cart?: CartData };

            if (flash.cart) {
                onCart?.(flash.cart);
            }
        },
    };

    const setQty = (qty: number) => {
        setBusy(true);
        router.patch(
            url(`/carrinho/itens/${line.id}/quantidade`),
            { qty },
            options,
        );
    };

    const remove = () => {
        setBusy(true);
        router.delete(url(`/carrinho/itens/${line.id}/remover`), options);
    };

    const max = line.maxQty ?? MAX_QTY;
    const problem = problemText(line, t);
    const lead =
        line.leadDays && line.mode
            ? t(
                  `detail.lead.${line.mode === 'in_stock' ? 'ready' : 'made_to_order'}`,
                  { min: line.leadDays[0], max: line.leadDays[1] },
              )
            : null;

    return (
        <li
            className={cn(
                'flex gap-3 py-4 md:gap-4',
                problem &&
                    'rounded-lg bg-warning-soft px-3 text-warning-soft-foreground',
            )}
        >
            <Link
                href={line.href}
                className="shrink-0 overflow-hidden rounded-md bg-muted"
                tabIndex={-1}
                aria-hidden="true"
            >
                {line.image ? (
                    <img
                        src={line.image}
                        alt=""
                        loading="lazy"
                        className={cn(
                            'object-cover',
                            compact ? 'size-16' : 'size-20 md:size-24',
                        )}
                    />
                ) : (
                    <span
                        className={cn(
                            'block',
                            compact ? 'size-16' : 'size-20 md:size-24',
                        )}
                    />
                )}
            </Link>

            <div className="flex min-w-0 flex-1 flex-col gap-2">
                <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0 text-sm">
                        <Link
                            href={line.href}
                            className="font-medium underline-offset-4 hover:underline"
                        >
                            {line.name}
                        </Link>
                        {line.label && (
                            <p className="text-muted-foreground">
                                {line.label}
                            </p>
                        )}
                        {line.personalization.length > 0 && (
                            <dl className="mt-1 text-muted-foreground">
                                {line.personalization.map((field) => (
                                    <div
                                        key={field.label}
                                        className="flex gap-1"
                                    >
                                        <dt>{field.label}:</dt>
                                        <dd className="min-w-0 break-words text-foreground">
                                            {field.value}
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                        )}
                        {line.surchargeCents > 0 && (
                            <p className="text-muted-foreground">
                                {t('cart.surcharge', {
                                    price: price(line.surchargeCents),
                                })}
                            </p>
                        )}
                        {lead && !problem && !compact && (
                            <p className="text-muted-foreground">{lead}</p>
                        )}
                    </div>
                    <div className="shrink-0 text-right text-sm">
                        <p className="font-medium">{price(line.lineCents)}</p>
                        {line.qty > 1 && (
                            <p className="text-xs text-muted-foreground">
                                {t('cart.each', {
                                    price: price(
                                        line.unitCents + line.surchargeCents,
                                    ),
                                })}
                            </p>
                        )}
                    </div>
                </div>

                {problem && <p className="text-sm font-medium">{problem}</p>}

                <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
                    {line.problem === null ? (
                        <QuantityStepper
                            value={line.qty}
                            max={max}
                            onChange={setQty}
                            disabled={busy}
                            label={t('cart.quantity_of', { name: line.name })}
                            className="h-9"
                        />
                    ) : (
                        line.problem === 'over_stock' &&
                        line.maxQty !== null &&
                        line.maxQty > 0 && (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                disabled={busy}
                                onClick={() => setQty(line.maxQty as number)}
                            >
                                {t('cart.keep', { count: line.maxQty })}
                            </Button>
                        )
                    )}
                    {line.problem === null &&
                        line.mode === 'in_stock' &&
                        line.maxQty !== null &&
                        line.maxQty < MAX_QTY && (
                            <span className="text-xs text-muted-foreground">
                                {t('cart.only_left', { count: line.maxQty })}
                            </span>
                        )}
                    <Button
                        type="button"
                        variant="link"
                        size="sm"
                        className="ml-auto h-auto px-0 text-muted-foreground"
                        disabled={busy}
                        onClick={remove}
                        aria-label={t('cart.remove_line', { name: line.name })}
                    >
                        {busy && <Spinner aria-hidden="true" />}
                        {t('cart.remove')}
                    </Button>
                </div>

                <InputError message={error ?? undefined} />
            </div>
        </li>
    );
}

/**
 * As linhas do carrinho, iguais no painel e na página. Cada mudança volta
 * com o carrinho refeito no flash `cart`; o painel passa-o a `onCart` para se
 * atualizar (a página recebe-o já nas props).
 */
export function CartLines({
    lines,
    compact = false,
    onCart,
}: {
    lines: CartLine[];
    compact?: boolean;
    onCart?: (cart: CartData) => void;
}) {
    return (
        <ul className="flex flex-col divide-y divide-border">
            {lines.map((line) => (
                <CartLineRow
                    key={line.id}
                    line={line}
                    compact={compact}
                    onCart={onCart}
                />
            ))}
        </ul>
    );
}
