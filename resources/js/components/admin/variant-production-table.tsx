import { router, useForm } from '@inertiajs/react';
import { Calculator } from 'lucide-react';
import { useId, useState } from 'react';
import { ColorSwatch } from '@/components/admin/color-swatch';
import { ConfirmDialog } from '@/components/admin/confirm-dialog';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatCents } from '@/lib/money';
import { productProduction } from '@/lib/production';
import { cn } from '@/lib/utils';
import { precos, producao } from '@/routes/admin/produtos/variantes';
import type { ProductEditing } from '@/types/catalog';

type Props = {
    editing: ProductEditing;
};

/** Strings, porque um campo vazio é "sem valor" — e é diferente de zero. */
type ProductionFormData = {
    hours: string;
    minutes: string;
    weight_grams: string;
};

const toForm = (editing: ProductEditing): ProductionFormData => {
    const { printingTimeMinutes, filamentWeightGrams } = productProduction(
        editing.variants,
    );

    return {
        hours:
            printingTimeMinutes === null
                ? ''
                : String(Math.floor(printingTimeMinutes / 60)),
        minutes:
            printingTimeMinutes === null
                ? ''
                : String(printingTimeMinutes % 60),
        weight_grams:
            filamentWeightGrams === null ? '' : String(filamentWeightGrams),
    };
};

/**
 * A aba "Produção" do produto: o tempo de impressão e a gramagem da peça —
 * um par só, para todas as variantes — e o botão que escreve em todas elas
 * os preços calculados.
 *
 * Um par e não um por variante porque a peça é a mesma: muda a cor e o
 * material, não o tempo de máquina nem o plástico gasto. O que faz o preço
 * sugerido diferir entre variantes é o preço/kg de cada material, e isso já
 * vive na ficha de cada uma.
 *
 * Os preços NÃO saem do que está escrito nos campos: saem do que está
 * gravado. Por isso o botão "Aplicar preços" fica desativado enquanto há
 * alterações por guardar — o preço que fica na variante tem de ser o mesmo
 * que a coluna "Sugerido" mostra, e essa coluna vem do servidor.
 */
export function VariantProductionTable({ editing }: Props) {
    const { data, setData, patch, processing, errors, isDirty, setDefaults } =
        useForm<ProductionFormData>(toForm(editing));
    const [confirming, setConfirming] = useState(false);
    const [applying, setApplying] = useState(false);
    const id = useId();

    const save = () =>
        patch(producao(editing.product.id).url, {
            // O servidor devolve `back()`: o modal fica onde está e a coluna
            // "Sugerido" chega recalculada com a prop `editing`.
            preserveScroll: true,
            preserveState: true,
            // O que acabou de ser guardado passa a ser a base do "por
            // guardar" — sem isto o botão de aplicar ficava trancado para
            // sempre depois do primeiro guardar.
            onSuccess: () => setDefaults(),
        });

    const applyPrices = () => {
        setApplying(true);
        router.post(
            precos(editing.product.id).url,
            {},
            {
                preserveScroll: true,
                preserveState: true,
                onFinish: () => {
                    setApplying(false);
                    setConfirming(false);
                },
            },
        );
    };

    const calculable = editing.variants.filter(
        (variant) => variant.suggestedRetailCents !== null,
    ).length;
    const withoutMaterial = editing.variants.filter(
        (variant) => variant.material === null,
    ).length;

    if (editing.variants.length === 0) {
        return (
            <p className="rounded-xl border border-dashed border-border p-6 text-center text-sm text-muted-foreground">
                Este produto ainda não tem variantes — cria a primeira na lista
                para lhe dar tempo de impressão e gramagem.
            </p>
        );
    }

    return (
        <div className="flex flex-col gap-4">
            <p className="text-xs text-muted-foreground">
                O que o slicer diz da peça. É igual para todas as variantes — o
                que muda entre elas é o material, e é daí que o preço sugerido
                difere. Sem tempo não há cálculo, e sem material o plástico
                seria de graça.
            </p>

            <div className="flex flex-wrap items-end gap-3 rounded-xl border border-border/60 bg-secondary/20 p-3">
                <div className="grid gap-1.5">
                    <Label htmlFor={`${id}-hours`}>Horas</Label>
                    <Input
                        id={`${id}-hours`}
                        type="number"
                        inputMode="numeric"
                        min={0}
                        max={999}
                        className="w-20"
                        value={data.hours}
                        onChange={(event) =>
                            setData('hours', event.target.value)
                        }
                        placeholder="0"
                    />
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor={`${id}-minutes`}>Min</Label>
                    <Input
                        id={`${id}-minutes`}
                        type="number"
                        inputMode="numeric"
                        min={0}
                        max={59}
                        className="w-20"
                        value={data.minutes}
                        onChange={(event) =>
                            setData('minutes', event.target.value)
                        }
                        placeholder="0"
                    />
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor={`${id}-grams`}>Gramas</Label>
                    <Input
                        id={`${id}-grams`}
                        type="number"
                        inputMode="numeric"
                        min={0}
                        max={99999}
                        className="w-24"
                        value={data.weight_grams}
                        onChange={(event) =>
                            setData('weight_grams', event.target.value)
                        }
                        placeholder="0"
                    />
                </div>
                <Button
                    type="button"
                    size="sm"
                    onClick={save}
                    disabled={processing || !isDirty}
                >
                    Guardar em todas
                </Button>
            </div>

            <InputError
                message={errors.hours ?? errors.minutes ?? errors.weight_grams}
            />

            <div className="overflow-x-auto rounded-xl border border-border/60">
                <table className="w-full text-sm">
                    <thead className="bg-secondary/40 text-xs text-muted-foreground">
                        <tr>
                            <th
                                scope="col"
                                className="px-3 py-2 text-left font-medium"
                            >
                                Variante
                            </th>
                            <th
                                scope="col"
                                className="px-3 py-2 text-right font-medium"
                            >
                                Sugerido
                            </th>
                            <th
                                scope="col"
                                className="px-3 py-2 text-right font-medium"
                            >
                                Atual
                            </th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-border/60">
                        {editing.variants.map((variant) => (
                            <tr
                                key={variant.id}
                                className={cn(
                                    'align-top',
                                    !variant.active && 'opacity-60',
                                )}
                            >
                                <td className="min-w-0 px-3 py-2">
                                    <span className="flex flex-wrap items-center gap-1.5 font-medium">
                                        {variant.sku}
                                        {variant.isDefault && (
                                            <Badge variant="outline">
                                                Principal
                                            </Badge>
                                        )}
                                        {!variant.active && (
                                            <Badge variant="secondary">
                                                Arquivada
                                            </Badge>
                                        )}
                                    </span>
                                    <span className="mt-0.5 flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
                                        {variant.color && (
                                            <>
                                                <ColorSwatch
                                                    hex={variant.color.hex}
                                                />
                                                <span>
                                                    {variant.color.name}
                                                </span>
                                            </>
                                        )}
                                        {variant.material ? (
                                            <span>{variant.material.name}</span>
                                        ) : (
                                            <span className="text-warning">
                                                Sem material
                                            </span>
                                        )}
                                        {variant.sizeLabel && (
                                            <span>{variant.sizeLabel}</span>
                                        )}
                                    </span>
                                </td>
                                <PriceCell
                                    retail={variant.suggestedRetailCents}
                                    wholesale={variant.suggestedWholesaleCents}
                                />
                                <PriceCell
                                    retail={variant.priceCents}
                                    wholesale={variant.wholesalePriceCents}
                                />
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <div className="flex flex-wrap items-center justify-end gap-2">
                {isDirty ? (
                    <span className="text-xs text-muted-foreground">
                        Guarda primeiro para aplicar preços.
                    </span>
                ) : (
                    withoutMaterial > 0 && (
                        <span className="text-xs text-muted-foreground">
                            {withoutMaterial === 1
                                ? '1 variante sem material fica de fora.'
                                : `${withoutMaterial} variantes sem material ficam de fora.`}
                        </span>
                    )
                )}
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => setConfirming(true)}
                    disabled={
                        processing || applying || isDirty || calculable === 0
                    }
                >
                    <Calculator className="size-4" />
                    Aplicar preços calculados
                </Button>
            </div>

            <ConfirmDialog
                open={confirming}
                onOpenChange={(open) => !open && setConfirming(false)}
                title="Aplicar preços calculados"
                description={
                    <>
                        O PVP e o preço de revenda de{' '}
                        <strong>
                            {calculable === 1
                                ? '1 variante'
                                : `${calculable} variantes`}
                        </strong>{' '}
                        passam a ser os sugeridos, arquivadas incluídas. As que
                        não têm material ficam como estão. Uma promoção
                        mantém-se se continuar abaixo do novo preço.
                    </>
                }
                confirmLabel="Aplicar"
                processing={applying}
                onConfirm={applyPrices}
            />
        </div>
    );
}

/** PVP em cima, revenda em baixo — o mesmo desenho no sugerido e no atual. */
function PriceCell({
    retail,
    wholesale,
}: {
    retail: number | null;
    wholesale: number | null;
}) {
    return (
        <td className="px-3 py-2 text-right tabular-nums">
            {retail === null ? (
                <span className="text-muted-foreground">—</span>
            ) : (
                <>
                    <span className="block">{formatCents(retail)}</span>
                    {wholesale !== null && (
                        <span className="block text-xs text-muted-foreground">
                            rev. {formatCents(wholesale)}
                        </span>
                    )}
                </>
            )}
        </td>
    );
}
