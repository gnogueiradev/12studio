import { router, useForm } from '@inertiajs/react';
import { Calculator } from 'lucide-react';
import { useId, useMemo, useState } from 'react';
import { ColorSwatch } from '@/components/admin/color-swatch';
import { ConfirmDialog } from '@/components/admin/confirm-dialog';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatCents } from '@/lib/money';
import { productionBySize } from '@/lib/production';
import type { SizeProduction } from '@/lib/production';
import { cn } from '@/lib/utils';
import { precos, producao } from '@/routes/admin/produtos/variantes';
import type { ProductEditing, VariantRow } from '@/types/catalog';

type Props = {
    editing: ProductEditing;
};

/** Strings, porque um campo vazio é "sem valor" — e é diferente de zero. */
type SizeFormRow = {
    /** O tamanho tal como o servidor o conhece. Null = variantes sem tamanho. */
    size_label: string | null;
    hours: string;
    minutes: string;
    weight_grams: string;
};

type ProductionFormData = {
    sizes: SizeFormRow[];
};

const toRow = (group: SizeProduction): SizeFormRow => ({
    size_label: group.sizeLabel,
    hours:
        group.printingTimeMinutes === null
            ? ''
            : String(Math.floor(group.printingTimeMinutes / 60)),
    minutes:
        group.printingTimeMinutes === null
            ? ''
            : String(group.printingTimeMinutes % 60),
    weight_grams:
        group.filamentWeightGrams === null
            ? ''
            : String(group.filamentWeightGrams),
});

/**
 * A aba "Produção" do produto: o tempo de impressão e a gramagem de cada
 * TAMANHO — um par por tamanho, para todas as variantes dele — e o botão que
 * escreve em todas elas os preços calculados.
 *
 * Um par por tamanho e não um por variante porque, dentro de um tamanho, a
 * peça é a mesma: muda a cor e o material, não o tempo de máquina nem o
 * plástico gasto. Entre tamanhos muda — a escultura de 20cm não é a de 10cm.
 * Um produto sem tamanhos tem um bloco só, e a aba fica como sempre foi.
 *
 * Os preços NÃO saem do que está escrito nos campos: saem do que está
 * gravado. Por isso o botão "Aplicar preços" fica desativado enquanto há
 * alterações por guardar — o preço que fica na variante tem de ser o mesmo
 * que a coluna "Sugerido" mostra, e essa coluna vem do servidor.
 *
 * O formulário nasce dos tamanhos que existem quando a aba monta. Quem chama
 * dá-lhe um `key` com esses tamanhos (ver form.tsx), para uma variante de um
 * tamanho novo remontar a aba em vez de a deixar a mandar a lista velha.
 */
export function VariantProductionTable({ editing }: Props) {
    const groups = useMemo(
        () => productionBySize(editing.variants),
        [editing.variants],
    );
    const { data, setData, patch, processing, errors, isDirty, setDefaults } =
        useForm<ProductionFormData>({ sizes: groups.map(toRow) });
    const [confirming, setConfirming] = useState(false);
    const [applying, setApplying] = useState(false);
    const id = useId();

    // Os erros chegam por caminho ("sizes.1.minutes"); o tipo do useForm só
    // conhece as chaves de topo.
    const errorFor = (index: number, field: keyof SizeFormRow) =>
        (errors as Record<string, string | undefined>)[
            `sizes.${index}.${field}`
        ];

    const setField = (
        index: number,
        field: 'hours' | 'minutes' | 'weight_grams',
        value: string,
    ) =>
        setData(
            'sizes',
            data.sizes.map((row, at) =>
                at === index ? { ...row, [field]: value } : row,
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

    const bySize = groups.length > 1;

    return (
        <div className="flex flex-col gap-4">
            <p className="text-xs text-muted-foreground">
                {bySize
                    ? 'O que o slicer diz de cada tamanho. É igual em todas as cores do mesmo tamanho'
                    : 'O que o slicer diz da peça. É igual para todas as variantes'}{' '}
                — o que muda entre elas é o material, e é daí que o preço
                sugerido difere. Sem tempo não há cálculo, e sem material o
                plástico seria de graça.
            </p>

            {groups.map((group, index) => {
                const row = data.sizes[index];

                if (row === undefined) {
                    return null;
                }

                return (
                    <fieldset
                        key={group.key}
                        className="flex flex-col gap-3 rounded-xl border border-border/60 p-3"
                    >
                        {bySize && (
                            <legend className="px-1 text-sm font-medium">
                                {group.sizeLabel ?? 'Sem tamanho'}
                                <span className="font-normal text-muted-foreground">
                                    {' · '}
                                    {group.variants.length === 1
                                        ? '1 variante'
                                        : `${group.variants.length} variantes`}
                                </span>
                            </legend>
                        )}

                        <div className="flex flex-wrap items-end gap-3 rounded-lg bg-secondary/20 p-3">
                            <div className="grid gap-1.5">
                                <Label htmlFor={`${id}-${index}-hours`}>
                                    Horas
                                </Label>
                                <Input
                                    id={`${id}-${index}-hours`}
                                    type="number"
                                    inputMode="numeric"
                                    min={0}
                                    max={999}
                                    className="w-20"
                                    value={row.hours}
                                    onChange={(event) =>
                                        setField(
                                            index,
                                            'hours',
                                            event.target.value,
                                        )
                                    }
                                    placeholder="0"
                                />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor={`${id}-${index}-minutes`}>
                                    Min
                                </Label>
                                <Input
                                    id={`${id}-${index}-minutes`}
                                    type="number"
                                    inputMode="numeric"
                                    min={0}
                                    max={59}
                                    className="w-20"
                                    value={row.minutes}
                                    onChange={(event) =>
                                        setField(
                                            index,
                                            'minutes',
                                            event.target.value,
                                        )
                                    }
                                    placeholder="0"
                                />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor={`${id}-${index}-grams`}>
                                    Gramas
                                </Label>
                                <Input
                                    id={`${id}-${index}-grams`}
                                    type="number"
                                    inputMode="numeric"
                                    min={0}
                                    max={99999}
                                    className="w-24"
                                    value={row.weight_grams}
                                    onChange={(event) =>
                                        setField(
                                            index,
                                            'weight_grams',
                                            event.target.value,
                                        )
                                    }
                                    placeholder="0"
                                />
                            </div>
                            {group.mixed && (
                                <span className="text-xs text-warning">
                                    As variantes deste tamanho têm valores
                                    diferentes — guardar iguala-as.
                                </span>
                            )}
                        </div>

                        <InputError
                            message={
                                errorFor(index, 'size_label') ??
                                errorFor(index, 'hours') ??
                                errorFor(index, 'minutes') ??
                                errorFor(index, 'weight_grams')
                            }
                        />

                        <VariantPriceTable variants={group.variants} />
                    </fieldset>
                );
            })}

            <InputError message={errors.sizes} />

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
                    size="sm"
                    onClick={save}
                    disabled={processing || !isDirty}
                >
                    Guardar
                </Button>
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

/** As variantes de um tamanho, com o preço sugerido ao lado do atual. */
function VariantPriceTable({ variants }: { variants: VariantRow[] }) {
    return (
        <div className="overflow-x-auto rounded-lg border border-border/60">
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
                    {variants.map((variant) => (
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
                                            <span>{variant.color.name}</span>
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
