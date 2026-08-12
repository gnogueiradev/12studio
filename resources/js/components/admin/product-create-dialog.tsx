import { useForm } from '@inertiajs/react';
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
import { formatCents, inputToCents } from '@/lib/money';
import { cn } from '@/lib/utils';
import { store } from '@/routes/admin/produtos';
import type {
    CategoryOption,
    ColorGroup,
    ProductQuickFormData,
} from '@/types/catalog';
import { FULFILLMENT_MODES } from '@/types/catalog';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    categories: CategoryOption[];
    colorGroups: ColorGroup[];
    defaultVatRate: number;
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
 * O design cruza Material × Cor como dois eixos independentes, mas na base de
 * dados uma cor PERTENCE a um material — "TPU × Dourado" pode não existir. Por
 * isso as chips de material FILTRAM a paleta em vez de multiplicarem: a matriz
 * real é cores × tamanhos, e todas as combinações que ela gera são válidas.
 */
export function ProductCreateDialog({
    open,
    onOpenChange,
    categories,
    colorGroups,
    defaultVatRate,
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
                sizes: [],
                price: '',
                filament_weight_grams: null,
                printing_time_minutes: null,
            },
        });

    /*
     * Materiais escolhidos vivem fora do formulário: não são um campo, são a
     * lente sobre a paleta. Vazio = mostrar tudo.
     */
    const [selectedMaterials, setSelectedMaterials] = useState<string[]>([]);

    /*
     * "Publicar" também não é um campo — é o que decide, no envio, se o
     * `status` sai como `active` ou fica em `draft`. Mantê-lo fora do
     * formulário poupa ter de o limpar do payload antes de cada post.
     */
    const [publish, setPublish] = useState(false);

    const colorsById = useMemo(
        () =>
            new Map(
                colorGroups.flatMap((group) =>
                    group.colors.map(
                        (color) =>
                            [
                                color.id,
                                { ...color, material: group.material },
                            ] as const,
                    ),
                ),
            ),
        [colorGroups],
    );

    const setVariants = (changes: Partial<ProductQuickFormData['variants']>) =>
        setData('variants', { ...data.variants, ...changes });

    const toggle = <T,>(list: readonly T[], value: T): T[] =>
        list.includes(value)
            ? list.filter((item) => item !== value)
            : [...list, value];

    const visibleColors = colorGroups
        .filter(
            (group) =>
                selectedMaterials.length === 0 ||
                selectedMaterials.includes(group.material),
        )
        .flatMap((group) => group.colors);

    /**
     * Desligar um material tira também as suas cores da seleção. Sem isto
     * ficavam cores escolhidas fora de vista a gerar variantes que já ninguém
     * via na matriz.
     */
    const toggleMaterial = (material: string) => {
        const next = toggle(selectedMaterials, material);

        setSelectedMaterials(next);

        if (next.length > 0) {
            setVariants({
                color_ids: data.variants.color_ids.filter((id) =>
                    next.includes(colorsById.get(id)?.material ?? ''),
                ),
            });
        }
    };

    /*
     * A mesma ordem que o ProductService usa para gerar (cores por fora,
     * tamanhos por dentro), para a pré-visualização não prometer uma coisa e
     * as variantes saírem por outra.
     *
     * O material entra na etiqueta quando a seleção atravessa mais do que um:
     * "Preto" existe em PLA e em PETG, e duas linhas iguais na matriz não
     * diriam qual é qual.
     */
    const combos = useMemo(() => {
        const sizes = data.variants.sizes.length ? data.variants.sizes : [null];
        const mixedMaterials =
            new Set(
                data.variants.color_ids.map(
                    (id) => colorsById.get(id)?.material,
                ),
            ).size > 1;

        return data.variants.color_ids.flatMap((colorId) => {
            const color = colorsById.get(colorId);

            return sizes.map((size) => ({
                key: `${colorId}-${size ?? ''}`,
                label: [
                    mixedMaterials
                        ? `${color?.material} ${color?.name}`
                        : color?.name,
                    size,
                ]
                    .filter((part) => part !== null && part !== undefined)
                    .join(' · '),
            }));
        });
    }, [data.variants.color_ids, data.variants.sizes, colorsById]);

    /*
     * Margem = preço menos o filamento gasto, ao preço/kg REAL da cor mais cara
     * escolhida (o design usava 0,025 €/g fixos, mas a app conhece o preço de
     * cada material). Sem mão de obra, energia nem embalagem — esses chegam com
     * o CostService.
     */
    const pricePerKgCents = data.variants.color_ids.reduce(
        (max, id) => Math.max(max, colorsById.get(id)?.pricePerKgCents ?? 0),
        0,
    );
    const priceCents = inputToCents(data.variants.price);
    const grams = data.variants.filament_weight_grams ?? 0;
    const marginCents =
        priceCents > 0 && grams > 0 && pricePerKgCents > 0
            ? priceCents - Math.round((grams * pricePerKgCents) / 1000)
            : null;

    const canCreate =
        data.name.trim() !== '' &&
        priceCents > 0 &&
        data.variants.color_ids.length > 0;

    const messages = errors as Record<string, string>;
    const variantsError = Object.entries(messages).find(([key]) =>
        key.startsWith('variants'),
    )?.[1];

    const submit = (status: string) => {
        transform((current) => ({ ...current, status }));

        post(store().url, {
            onSuccess: () => {
                reset();
                setSelectedMaterials([]);
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
                        Escolhe as cores e os tamanhos — as variantes são
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

                    <div className="flex items-center justify-between gap-4 rounded-xl border border-border/60 bg-secondary/40 px-4 py-3">
                        <span>
                            <span className="block text-sm">
                                Margem estimada
                            </span>
                            <span className="mt-0.5 block text-xs text-muted-foreground">
                                {pricePerKgCents > 0
                                    ? `Filamento a ${formatCents(pricePerKgCents)}/kg, sem mão de obra.`
                                    : 'Escolhe uma cor para saber o custo do filamento.'}
                            </span>
                        </span>
                        <span
                            className={cn(
                                'text-lg font-semibold tabular-nums',
                                marginCents === null && 'text-muted-foreground',
                                marginCents !== null &&
                                    marginCents <= 0 &&
                                    'text-destructive',
                                marginCents !== null &&
                                    marginCents > 0 &&
                                    'text-success',
                            )}
                        >
                            {marginCents === null
                                ? '—'
                                : formatCents(marginCents)}
                        </span>
                    </div>

                    <div className="flex flex-col gap-4 border-t border-border/60 pt-5">
                        <div className="flex items-baseline justify-between gap-3">
                            <Label>Variantes</Label>
                            <p className="text-xs text-muted-foreground">
                                Cada combinação fica com stock e preço próprios.
                            </p>
                        </div>

                        {colorGroups.length === 0 ? (
                            <p className="rounded-xl border border-dashed border-border p-6 text-center text-sm text-muted-foreground">
                                Ainda não há cores ativas. Cria um material e
                                uma cor antes de gerar variantes.
                            </p>
                        ) : (
                            <>
                                <div className="grid gap-2">
                                    <span className="text-xs text-muted-foreground">
                                        Material{' '}
                                        <span className="opacity-70">
                                            (filtra as cores)
                                        </span>
                                    </span>
                                    <div className="flex flex-wrap gap-2">
                                        {colorGroups.map((group) => (
                                            <ToggleChip
                                                key={group.material}
                                                active={selectedMaterials.includes(
                                                    group.material,
                                                )}
                                                onClick={() =>
                                                    toggleMaterial(
                                                        group.material,
                                                    )
                                                }
                                            >
                                                {group.material}
                                            </ToggleChip>
                                        ))}
                                    </div>
                                </div>

                                <div className="grid gap-2">
                                    <span className="text-xs text-muted-foreground">
                                        Cor
                                    </span>
                                    <div className="flex flex-wrap gap-2">
                                        {visibleColors.map((color) => (
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
                                        ? 'Escolhe pelo menos uma cor'
                                        : combos.length === 1
                                          ? '1 variante vai ser criada'
                                          : `${combos.length} variantes vão ser criadas`}
                                </span>
                                {combos.length > 0 && (
                                    <span className="text-xs text-muted-foreground">
                                        {[
                                            `${data.variants.color_ids.length} ${data.variants.color_ids.length === 1 ? 'cor' : 'cores'}`,
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
