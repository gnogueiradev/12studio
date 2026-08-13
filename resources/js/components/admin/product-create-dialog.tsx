import { router, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { ToggleChip } from '@/components/admin/toggle-chip';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { centsToInput, formatCents, inputToCents } from '@/lib/money';
import { cn } from '@/lib/utils';
import { store } from '@/routes/admin/produtos';
import type {
    CategoryOption,
    ColorOption,
    MaterialOption,
    ProductQuickFormData,
} from '@/types/catalog';
import { FULFILLMENT_MODES } from '@/types/catalog';
import type { PricingBreakdown } from '@/types/pricing';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    categories: CategoryOption[];
    colors: ColorOption[];
    materials: MaterialOption[];
    defaultVatRate: number;
    /** Calculado no servidor, recarregado em `only: ['pricingPreview']`. */
    pricingPreview: { result: PricingBreakdown | null };
};

const NO_CATEGORY = 'none';

/** Quantas combinações se pré-visualizam antes do "+N". */
const PREVIEW_LIMIT = 6;

/**
 * Tamanhos oferecidos. São `size_label` — texto livre na base de dados — mas
 * aqui vão como presets: a matriz existe para despachar o caso comum, e quem
 * precisar de "XL" ou "30 cm" escreve-o na variante depois.
 */
const SIZES = ['Pequeno', 'Médio', 'Grande'] as const;

const MODE_HINTS: Record<string, string> = {
    in_stock: 'Já impresso, envia no dia.',
    made_to_order: 'Entra na fila de impressão.',
    custom: 'Feito à medida — sem direito a devolução.',
};

/**
 * Criar um produto e as suas variantes de uma vez.
 *
 * A matriz é Cor × Material × Tamanho — três eixos que se MULTIPLICAM. Nem
 * sempre foi assim: enquanto uma cor pertenceu a um material, "TPU × Dourado"
 * podia não existir, e as chips de material limitavam-se a filtrar a paleta. Com
 * a ligação fora, qualquer cor imprime-se em qualquer bobine e todas as
 * combinações que a matriz gera são válidas por construção.
 *
 * Cor e material são obrigatórios; o tamanho não. São eles que definem uma peça
 * imprimível — que tom, e em que filamento.
 */
export function ProductCreateDialog({
    open,
    onOpenChange,
    categories,
    colors,
    materials,
    defaultVatRate,
    pricingPreview,
}: Props) {
    const { data, setData, post, transform, processing, errors, reset } =
        useForm<ProductQuickFormData>({
            name: '',
            category_id: null,
            description: '',
            status: 'draft',
            fulfillment_mode: 'in_stock',
            production_time_days: null,
            vat_rate: defaultVatRate,
            variants: {
                color_ids: [],
                material_ids: [],
                sizes: [],
                price: '',
                filament_weight_grams: null,
                printing_time_minutes: null,
            },
        });

    /*
     * "Publicar" também não é um campo — é o que decide, no envio, se o
     * `status` sai como `active` ou fica em `draft`. Mantê-lo fora do
     * formulário poupa ter de o limpar do payload antes de cada post.
     */
    const [publish, setPublish] = useState(false);

    const colorsById = useMemo(
        () => new Map(colors.map((color) => [color.id, color] as const)),
        [colors],
    );

    const materialsById = useMemo(
        () =>
            new Map(
                materials.map((material) => [material.id, material] as const),
            ),
        [materials],
    );

    const setVariants = (changes: Partial<ProductQuickFormData['variants']>) =>
        setData('variants', { ...data.variants, ...changes });

    const toggle = <T,>(list: readonly T[], value: T): T[] =>
        list.includes(value)
            ? list.filter((item) => item !== value)
            : [...list, value];

    /*
     * A mesma ordem que o ProductService usa para gerar (cor por fora, material
     * no meio, tamanho por dentro), para a pré-visualização não prometer uma
     * coisa e as variantes saírem por outra.
     */
    const combos = useMemo(() => {
        const sizes = data.variants.sizes.length ? data.variants.sizes : [null];

        return data.variants.color_ids.flatMap((colorId) =>
            data.variants.material_ids.flatMap((materialId) =>
                sizes.map((size) => ({
                    key: `${colorId}-${materialId}-${size ?? ''}`,
                    label: [
                        colorsById.get(colorId)?.name,
                        materialsById.get(materialId)?.name,
                        size,
                    ]
                        .filter((part) => part !== null && part !== undefined)
                        .join(' · '),
                })),
            ),
        );
    }, [
        data.variants.color_ids,
        data.variants.material_ids,
        data.variants.sizes,
        colorsById,
        materialsById,
    ]);

    const priceCents = inputToCents(data.variants.price);
    const grams = data.variants.filament_weight_grams ?? 0;
    const minutes = data.variants.printing_time_minutes ?? 0;

    /*
     * O material mais caro dos escolhidos: a matriz gera uma variante por
     * material, e o preço tem de cobrir o mais caro deles. A cor não entra na
     * conta — não tem preço.
     */
    const dearestMaterialId = data.variants.material_ids.reduce<number | null>(
        (dearest, id) =>
            (materialsById.get(id)?.pricePerKgCents ?? 0) >
            (dearest === null
                ? -1
                : (materialsById.get(dearest)?.pricePerKgCents ?? 0))
                ? id
                : dearest,
        null,
    );

    const canSuggest = grams > 0 && minutes > 0 && dearestMaterialId !== null;

    /*
     * O preço sugerido vem do SERVIDOR, com o mesmo motor da calculadora — não
     * de uma estimativa aqui. Botão e não debounce: isto é um modal, e um
     * pedido por tecla enquanto se preenche uma matriz de variantes era ruído.
     */
    const suggest = () =>
        router.reload({
            only: ['pricingPreview'],
            data: {
                weight_grams: grams,
                hours: Math.floor(minutes / 60),
                minutes: minutes % 60,
                material_id: dearestMaterialId,
            },
        });

    const suggestion = pricingPreview.result;

    /** Lucro sobre o preço escrito, com o custo real — não só o do filamento. */
    const marginCents =
        suggestion && priceCents > 0
            ? priceCents - suggestion.productionCostCents
            : null;

    // Cor E material, a espelhar o ProductService::generateVariants().
    const canCreate =
        data.name.trim() !== '' &&
        priceCents > 0 &&
        data.variants.color_ids.length > 0 &&
        data.variants.material_ids.length > 0;

    const messages = errors as Record<string, string>;
    const variantsError = Object.entries(messages).find(([key]) =>
        key.startsWith('variants'),
    )?.[1];

    const submit = (status: string) => {
        transform((current) => ({ ...current, status }));

        post(store().url, {
            onSuccess: () => {
                reset();
                setPublish(false);
                onOpenChange(false);
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[88vh] gap-0 overflow-y-auto p-0 sm:max-w-2xl">
                <DialogHeader className="border-b border-border/60 p-6">
                    <DialogTitle>Novo produto</DialogTitle>
                    <DialogDescription>
                        Cruza cores, materiais e tamanhos — as variantes são
                        criadas automaticamente.
                    </DialogDescription>
                </DialogHeader>

                <div className="flex flex-col gap-6 p-6">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2 sm:col-span-2">
                            <Label htmlFor="product-name">
                                Nome do produto
                            </Label>
                            <Input
                                id="product-name"
                                value={data.name}
                                onChange={(event) =>
                                    setData('name', event.target.value)
                                }
                                placeholder="Vaso ondulado"
                                maxLength={120}
                                autoFocus
                            />
                            <InputError message={errors.name} />
                        </div>

                        <div className="grid gap-2">
                            <Label>Categoria</Label>
                            <Select
                                value={
                                    data.category_id === null
                                        ? NO_CATEGORY
                                        : String(data.category_id)
                                }
                                onValueChange={(value) =>
                                    setData(
                                        'category_id',
                                        value === NO_CATEGORY
                                            ? null
                                            : Number(value),
                                    )
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Sem categoria" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NO_CATEGORY}>
                                        Sem categoria
                                    </SelectItem>
                                    {categories.map((category) => (
                                        <SelectItem
                                            key={category.id}
                                            value={String(category.id)}
                                        >
                                            {category.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.category_id} />
                        </div>

                        {data.fulfillment_mode !== 'in_stock' && (
                            <div className="grid gap-2">
                                <Label htmlFor="production-days">
                                    Prazo de produção
                                </Label>
                                <div className="relative">
                                    <Input
                                        id="production-days"
                                        type="number"
                                        min={0}
                                        max={60}
                                        value={data.production_time_days ?? ''}
                                        onChange={(event) =>
                                            setData(
                                                'production_time_days',
                                                event.target.value === ''
                                                    ? null
                                                    : Number(
                                                          event.target.value,
                                                      ),
                                            )
                                        }
                                        placeholder="3"
                                        className="pr-12 tabular-nums"
                                    />
                                    <span className="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm text-muted-foreground">
                                        dias
                                    </span>
                                </div>
                                <InputError
                                    message={errors.production_time_days}
                                />
                            </div>
                        )}
                    </div>

                    <div className="grid gap-2">
                        <Label>Como é produzido</Label>
                        <div className="grid gap-2 sm:grid-cols-3">
                            {FULFILLMENT_MODES.map((mode) => {
                                const active =
                                    data.fulfillment_mode === mode.value;

                                return (
                                    <button
                                        key={mode.value}
                                        type="button"
                                        onClick={() =>
                                            setData(
                                                'fulfillment_mode',
                                                mode.value,
                                            )
                                        }
                                        aria-pressed={active}
                                        className={cn(
                                            'rounded-xl border p-3 text-left transition-colors',
                                            active
                                                ? 'border-ring bg-secondary'
                                                : 'border-border hover:bg-secondary/60',
                                        )}
                                    >
                                        <span className="block text-sm font-semibold">
                                            {mode.label}
                                        </span>
                                        <span className="mt-0.5 block text-xs text-muted-foreground">
                                            {MODE_HINTS[mode.value]}
                                        </span>
                                    </button>
                                );
                            })}
                        </div>
                        <InputError message={errors.fulfillment_mode} />
                    </div>

                    <div className="grid gap-4 sm:grid-cols-3">
                        <div className="grid gap-2">
                            <Label htmlFor="variant-price">
                                Preço de venda
                            </Label>
                            <div className="relative">
                                <Input
                                    id="variant-price"
                                    type="number"
                                    step="0.5"
                                    min={0}
                                    value={data.variants.price}
                                    onChange={(event) =>
                                        setVariants({
                                            price: event.target.value,
                                        })
                                    }
                                    placeholder="29,00"
                                    className="pr-8 tabular-nums"
                                />
                                <span className="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm text-muted-foreground">
                                    €
                                </span>
                            </div>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="variant-grams">Filamento</Label>
                            <div className="relative">
                                <Input
                                    id="variant-grams"
                                    type="number"
                                    min={0}
                                    value={
                                        data.variants.filament_weight_grams ??
                                        ''
                                    }
                                    onChange={(event) =>
                                        setVariants({
                                            filament_weight_grams:
                                                event.target.value === ''
                                                    ? null
                                                    : Number(
                                                          event.target.value,
                                                      ),
                                        })
                                    }
                                    placeholder="84"
                                    className="pr-8 tabular-nums"
                                />
                                <span className="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm text-muted-foreground">
                                    g
                                </span>
                            </div>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="variant-minutes">
                                Tempo de impressão
                            </Label>
                            <div className="relative">
                                <Input
                                    id="variant-minutes"
                                    type="number"
                                    min={0}
                                    value={
                                        data.variants.printing_time_minutes ??
                                        ''
                                    }
                                    onChange={(event) =>
                                        setVariants({
                                            printing_time_minutes:
                                                event.target.value === ''
                                                    ? null
                                                    : Number(
                                                          event.target.value,
                                                      ),
                                        })
                                    }
                                    placeholder="130"
                                    className="pr-12 tabular-nums"
                                />
                                <span className="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm text-muted-foreground">
                                    min
                                </span>
                            </div>
                        </div>
                    </div>

                    <div className="flex flex-col gap-3 rounded-xl border border-border/60 bg-secondary/40 px-4 py-3">
                        <div className="flex items-center justify-between gap-4">
                            <span>
                                <span className="block text-sm">
                                    Custo real estimado
                                </span>
                                <span className="mt-0.5 block text-xs text-muted-foreground">
                                    {suggestion
                                        ? 'Material, máquina, manuseamento e risco — o material mais caro dos escolhidos.'
                                        : 'Preenche a gramagem, o tempo e um material para calcular.'}
                                </span>
                            </span>
                            <span className="text-lg font-semibold tabular-nums">
                                {suggestion
                                    ? formatCents(
                                          suggestion.productionCostCents,
                                      )
                                    : '—'}
                            </span>
                        </div>

                        <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border/60 pt-3">
                            {suggestion ? (
                                <span className="text-xs text-muted-foreground">
                                    Sugerido: revenda{' '}
                                    <strong className="text-foreground tabular-nums">
                                        {formatCents(
                                            suggestion.resalePriceCents,
                                        )}
                                    </strong>
                                    , cliente{' '}
                                    <strong className="text-foreground tabular-nums">
                                        {formatCents(
                                            suggestion.retailPriceCents,
                                        )}
                                    </strong>
                                    {marginCents !== null && (
                                        <>
                                            {' · '}
                                            lucro deste preço{' '}
                                            <strong
                                                className={cn(
                                                    'tabular-nums',
                                                    marginCents <= 0
                                                        ? 'text-destructive'
                                                        : 'text-success',
                                                )}
                                            >
                                                {formatCents(marginCents)}
                                            </strong>
                                        </>
                                    )}
                                </span>
                            ) : (
                                <span className="text-xs text-muted-foreground">
                                    O tempo de impressão conta tanto como o
                                    plástico.
                                </span>
                            )}

                            {/*
                             * O botão fica sempre: mexer na gramagem ou no tempo
                             * depois de calcular deixava um número obsoleto no
                             * ecrã sem nada que o dissesse.
                             */}
                            <span className="flex gap-2">
                                {suggestion && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            setVariants({
                                                price: centsToInput(
                                                    suggestion.retailPriceCents,
                                                ),
                                            })
                                        }
                                    >
                                        Usar{' '}
                                        {formatCents(
                                            suggestion.retailPriceCents,
                                        )}
                                    </Button>
                                )}
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    disabled={!canSuggest}
                                    onClick={suggest}
                                >
                                    {suggestion ? 'Recalcular' : 'Calcular'}
                                </Button>
                            </span>
                        </div>
                    </div>

                    <div className="flex flex-col gap-4 border-t border-border/60 pt-5">
                        <div className="flex items-baseline justify-between gap-3">
                            <Label>Variantes</Label>
                            <p className="text-xs text-muted-foreground">
                                Cada combinação fica com stock e preço próprios.
                            </p>
                        </div>

                        {colors.length === 0 || materials.length === 0 ? (
                            <p className="rounded-xl border border-dashed border-border p-6 text-center text-sm text-muted-foreground">
                                {colors.length === 0
                                    ? 'Ainda não há cores ativas. Cria uma antes de gerar variantes.'
                                    : 'Ainda não há materiais ativos. Cria um antes de gerar variantes.'}
                            </p>
                        ) : (
                            <>
                                <div className="grid gap-2">
                                    <span className="text-xs text-muted-foreground">
                                        Cor
                                    </span>
                                    <div className="flex flex-wrap gap-2">
                                        {colors.map((color) => (
                                            <ToggleChip
                                                key={color.id}
                                                active={data.variants.color_ids.includes(
                                                    color.id,
                                                )}
                                                onClick={() =>
                                                    setVariants({
                                                        color_ids: toggle(
                                                            data.variants
                                                                .color_ids,
                                                            color.id,
                                                        ),
                                                    })
                                                }
                                            >
                                                <span
                                                    className="size-3 rounded-full border border-border"
                                                    style={{
                                                        background: color.hex,
                                                    }}
                                                />
                                                {color.name}
                                            </ToggleChip>
                                        ))}
                                    </div>
                                </div>

                                <div className="grid gap-2">
                                    <span className="text-xs text-muted-foreground">
                                        Material
                                    </span>
                                    <div className="flex flex-wrap gap-2">
                                        {materials.map((material) => (
                                            <ToggleChip
                                                key={material.id}
                                                active={data.variants.material_ids.includes(
                                                    material.id,
                                                )}
                                                onClick={() =>
                                                    setVariants({
                                                        material_ids: toggle(
                                                            data.variants
                                                                .material_ids,
                                                            material.id,
                                                        ),
                                                    })
                                                }
                                            >
                                                {material.name}
                                                <span className="text-muted-foreground tabular-nums">
                                                    {formatCents(
                                                        material.pricePerKgCents,
                                                    )}
                                                </span>
                                            </ToggleChip>
                                        ))}
                                    </div>
                                </div>
                            </>
                        )}

                        <div className="grid gap-2">
                            <span className="text-xs text-muted-foreground">
                                Tamanho{' '}
                                <span className="opacity-70">(opcional)</span>
                            </span>
                            <div className="flex flex-wrap gap-2">
                                {SIZES.map((size) => (
                                    <ToggleChip
                                        key={size}
                                        active={data.variants.sizes.includes(
                                            size,
                                        )}
                                        onClick={() =>
                                            setVariants({
                                                sizes: toggle(
                                                    data.variants.sizes,
                                                    size,
                                                ),
                                            })
                                        }
                                    >
                                        {size}
                                    </ToggleChip>
                                ))}
                            </div>
                        </div>

                        <div className="rounded-xl border border-border/60 bg-secondary/40 px-4 py-3">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <span
                                    className={cn(
                                        'text-sm font-semibold',
                                        combos.length === 0 &&
                                            'font-normal text-muted-foreground',
                                    )}
                                >
                                    {combos.length === 0
                                        ? 'Escolhe pelo menos uma cor e um material'
                                        : combos.length === 1
                                          ? '1 variante vai ser criada'
                                          : `${combos.length} variantes vão ser criadas`}
                                </span>
                                {combos.length > 0 && (
                                    <span className="text-xs text-muted-foreground">
                                        {[
                                            `${data.variants.color_ids.length} ${data.variants.color_ids.length === 1 ? 'cor' : 'cores'}`,
                                            `${data.variants.material_ids.length} ${data.variants.material_ids.length === 1 ? 'material' : 'materiais'}`,
                                            data.variants.sizes.length === 0
                                                ? null
                                                : `${data.variants.sizes.length} ${data.variants.sizes.length === 1 ? 'tamanho' : 'tamanhos'}`,
                                        ]
                                            .filter((part) => part !== null)
                                            .join(' × ')}
                                    </span>
                                )}
                            </div>

                            {combos.length > 0 && (
                                <div className="mt-2.5 flex flex-wrap gap-1.5">
                                    {combos
                                        .slice(0, PREVIEW_LIMIT)
                                        .map((combo) => (
                                            <span
                                                key={combo.key}
                                                className="rounded-md border border-border/60 bg-card px-2 py-1 text-xs text-muted-foreground"
                                            >
                                                {combo.label}
                                            </span>
                                        ))}
                                    {combos.length > PREVIEW_LIMIT && (
                                        <span className="px-1 py-1 text-xs text-muted-foreground">
                                            +{combos.length - PREVIEW_LIMIT}
                                        </span>
                                    )}
                                </div>
                            )}

                            <p className="mt-2.5 text-xs text-muted-foreground">
                                As referências são geradas a partir do nome. O
                                stock começa a zero — a primeira contagem entra
                                pelo registo de stock de cada variante.
                            </p>
                        </div>

                        <InputError message={variantsError} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="product-description">
                            Descrição na loja
                        </Label>
                        <Textarea
                            id="product-description"
                            rows={2}
                            value={data.description}
                            onChange={(event) =>
                                setData('description', event.target.value)
                            }
                            placeholder="Vaso impresso em PLA reciclado, ideal para plantas pequenas."
                        />
                        <InputError message={errors.description} />
                    </div>
                </div>

                <DialogFooter className="items-center gap-3 border-t border-border/60 bg-secondary/30 p-6 sm:justify-between">
                    <label className="flex items-center gap-2 text-sm text-muted-foreground">
                        <Checkbox
                            checked={publish}
                            onCheckedChange={(checked) =>
                                setPublish(checked === true)
                            }
                        />
                        Publicar na loja online
                    </label>
                    <div className="flex gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            className="rounded-full"
                            disabled={processing || data.name.trim() === ''}
                            onClick={() => submit('draft')}
                        >
                            Guardar rascunho
                        </Button>
                        <Button
                            type="button"
                            className="rounded-full"
                            disabled={processing || !canCreate}
                            onClick={() => submit(publish ? 'active' : 'draft')}
                        >
                            Criar produto
                        </Button>
                    </div>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
