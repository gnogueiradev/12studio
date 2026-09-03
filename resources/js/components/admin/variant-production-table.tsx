import { router, useForm } from '@inertiajs/react';
import { Calculator } from 'lucide-react';
import { useState } from 'react';
import { ColorSwatch } from '@/components/admin/color-swatch';
import { ConfirmDialog } from '@/components/admin/confirm-dialog';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatCents } from '@/lib/money';
import { cn } from '@/lib/utils';
import { precos, producao } from '@/routes/admin/produtos/variantes';
import type { ProductEditing, VariantRow } from '@/types/catalog';

type Props = {
    editing: ProductEditing;
};

/** Uma linha do formulário: strings, porque um campo vazio é "sem valor". */
type ProductionRow = {
    id: number;
    hours: string;
    minutes: string;
    weight_grams: string;
};

type ProductionFormData = {
    rows: ProductionRow[];
};

const toRow = (variant: VariantRow): ProductionRow => {
    const minutes = variant.printingTimeMinutes;

    return {
        id: variant.id,
        hours: minutes === null ? '' : String(Math.floor(minutes / 60)),
        minutes: minutes === null ? '' : String(minutes % 60),
        weight_grams:
            variant.filamentWeightGrams === null
                ? ''
                : String(variant.filamentWeightGrams),
    };
};

/**
 * A aba "Produção" do produto: tempo de impressão e gramagem de todas as
 * variantes numa tabela só, e o botão que escreve nelas os preços calculados.
 *
 * Existe porque preencher isto variante a variante — abrir a ficha, escrever
 * dois números, guardar, voltar, abrir a seguinte — era o que fazia os campos
 * ficarem vazios. Aqui é uma linha por variante e um guardar para todas.
 *
 * Os preços NÃO saem do que está escrito na tabela: saem do que está gravado.
 * Por isso o botão "Aplicar preços" fica desativado enquanto há alterações por
 * guardar — o preço que fica na variante tem de ser o mesmo que a coluna
 * "Sugerido" mostra, e essa coluna vem do servidor.
 */
export function VariantProductionTable({ editing }: Props) {
    const { data, setData, patch, processing, errors, isDirty, setDefaults } =
        useForm<ProductionFormData>({
            rows: editing.variants.map(toRow),
        });
    const [confirming, setConfirming] = useState(false);
    const [applying, setApplying] = useState(false);

    const variantsById = new Map(
        editing.variants.map((variant) => [variant.id, variant]),
    );

    const setRow = (index: number, changes: Partial<ProductionRow>) =>
        setData(
            'rows',
            data.rows.map((row, i) =>
                i === index ? { ...row, ...changes } : row,
            ),
        );

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
        (variant) => variant.active && variant.suggestedRetailCents !== null,
    ).length;

    const rowErrors = Object.keys(errors).filter((key) =>
        key.startsWith('rows'),
    );

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
                O que o slicer diz de cada variante. É daqui que sai o preço
                sugerido — sem tempo não há cálculo, e sem material o plástico
                seria de graça.
            </p>

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
                                className="px-2 py-2 text-left font-medium"
                            >
                                Horas
                            </th>
                            <th
                                scope="col"
                                className="px-2 py-2 text-left font-medium"
                            >
                                Min
                            </th>
                            <th
                                scope="col"
                                className="px-2 py-2 text-left font-medium"
                            >
                                Gramas
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
                        {data.rows.map((row, index) => {
                            const variant = variantsById.get(row.id);

                            if (!variant) {
                                return null;
                            }

                            return (
                                <tr
                                    key={row.id}
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
                                                <span>
                                                    {variant.material.name}
                                                </span>
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
                                    <td className="px-2 py-2">
                                        <Input
                                            type="number"
                                            inputMode="numeric"
                                            min={0}
                                            max={999}
                                            className="w-16"
                                            aria-label={`Horas de impressão de ${variant.sku}`}
                                            value={row.hours}
                                            onChange={(event) =>
                                                setRow(index, {
                                                    hours: event.target.value,
                                                })
                                            }
                                            placeholder="0"
                                        />
                                    </td>
                                    <td className="px-2 py-2">
                                        <Input
                                            type="number"
                                            inputMode="numeric"
                                            min={0}
                                            max={59}
                                            className="w-16"
                                            aria-label={`Minutos de impressão de ${variant.sku}`}
                                            value={row.minutes}
                                            onChange={(event) =>
                                                setRow(index, {
                                                    minutes: event.target.value,
                                                })
                                            }
                                            placeholder="0"
                                        />
                                    </td>
                                    <td className="px-2 py-2">
                                        <Input
                                            type="number"
                                            inputMode="numeric"
                                            min={0}
                                            max={99999}
                                            className="w-20"
                                            aria-label={`Gramagem de ${variant.sku}`}
                                            value={row.weight_grams}
                                            onChange={(event) =>
                                                setRow(index, {
                                                    weight_grams:
                                                        event.target.value,
                                                })
                                            }
                                            placeholder="0"
                                        />
                                    </td>
                                    <td className="px-3 py-2 text-right tabular-nums">
                                        {variant.suggestedRetailCents ===
                                        null ? (
                                            <span className="text-muted-foreground">
                                                —
                                            </span>
                                        ) : (
                                            <>
                                                <span className="block">
                                                    {formatCents(
                                                        variant.suggestedRetailCents,
                                                    )}
                                                </span>
                                                {variant.suggestedWholesaleCents !==
                                                    null && (
                                                    <span className="block text-xs text-muted-foreground">
                                                        rev.{' '}
                                                        {formatCents(
                                                            variant.suggestedWholesaleCents,
                                                        )}
                                                    </span>
                                                )}
                                            </>
                                        )}
                                    </td>
                                    <td className="px-3 py-2 text-right tabular-nums">
                                        <span className="block">
                                            {formatCents(variant.priceCents)}
                                        </span>
                                        {variant.wholesalePriceCents !==
                                            null && (
                                            <span className="block text-xs text-muted-foreground">
                                                rev.{' '}
                                                {formatCents(
                                                    variant.wholesalePriceCents,
                                                )}
                                            </span>
                                        )}
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>

            {rowErrors.length > 0 && (
                <InputError
                    message={
                        errors[rowErrors[0] as keyof typeof errors] as string
                    }
                />
            )}

            <div className="flex flex-wrap items-center justify-between gap-3">
                <Button
                    type="button"
                    size="sm"
                    onClick={save}
                    disabled={processing || !isDirty}
                >
                    Guardar tempos e gramagens
                </Button>

                <div className="flex items-center gap-2">
                    {isDirty && (
                        <span className="text-xs text-muted-foreground">
                            Guarda primeiro para aplicar preços.
                        </span>
                    )}
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => setConfirming(true)}
                        disabled={
                            processing ||
                            applying ||
                            isDirty ||
                            calculable === 0
                        }
                    >
                        <Calculator className="size-4" />
                        Aplicar preços calculados
                    </Button>
                </div>
            </div>

            <ConfirmDialog
                open={confirming}
                onOpenChange={(open) => !open && setConfirming(false)}
                title="Aplicar preços calculados"
                description={
                    <>
                        O preço normal e o de revenda de{' '}
                        <strong>
                            {calculable === 1
                                ? '1 variante'
                                : `${calculable} variantes`}
                        </strong>{' '}
                        passam a ser os sugeridos. As que não têm gramagem,
                        tempo ou material ficam como estão. Uma promoção
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
