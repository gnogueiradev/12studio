import { router, useForm } from '@inertiajs/react';
import { Archive, Upload, X } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { ColorSwatch } from '@/components/admin/color-swatch';
import { ColorSwatchGrid } from '@/components/admin/color-swatch-grid';
import { ConfirmDialog } from '@/components/admin/confirm-dialog';
import { ProductImages } from '@/components/admin/product-images';
import { RichTextEditor } from '@/components/admin/rich-text-editor';
import { TagInput } from '@/components/admin/tag-input';
import { ToggleChip } from '@/components/admin/toggle-chip';
import type { VariantPricingPreview } from '@/components/admin/variant-form';
import { VariantPanel } from '@/components/admin/variant-panel';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
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
import { formatCents, inputToCents } from '@/lib/money';
import { cn } from '@/lib/utils';
import { store, update } from '@/routes/admin/produtos';
import { destroy as destroyVariant } from '@/routes/admin/variantes';
import type {
    CategoryOption,
    ColorOption,
    MaterialOption,
    ProductEditing,
    ProductFormData,
    VariantRow,
} from '@/types/catalog';
import { FULFILLMENT_MODES, PRODUCT_STATUSES } from '@/types/catalog';
import type { PrinterProfileOption } from '@/types/pricing';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Null cria; um produto carregado edita esse produto. */
    editing: ProductEditing | null;
    categories: CategoryOption[];
    colors: ColorOption[];
    materials: MaterialOption[];
    printers: PrinterProfileOption[];
    /** Para o painel de custo da ficha de variante. Recarrega-se sozinha. */
    pricing: VariantPricingPreview;
    tagSuggestions: string[];
    defaultVatRate: number;
};

/** O alvo da ficha de variante: uma que existe, uma nova, ou nenhuma. */
type VariantTarget = VariantRow | 'new' | null;

const NO_CATEGORY = 'none';

/** Quantas combinações se pré-visualizam antes do "+N". */
const PREVIEW_LIMIT = 6;

/** O mesmo tecto do StoreProductRequest. */
const MAX_PHOTOS = 10;

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
 * Criar e editar um produto, no mesmo modal.
 *
 * Ao criar, a matriz é Cor × Material × Tamanho — mas é uma INTERSECÇÃO, não um
 * produto cartesiano. Cada cor declara em que filamentos existe (`materialIds`),
 * e o par que o dono não tem cai fora: escolher Rosa+Preto em PLA+Silk gera três
 * variantes e não quatro, porque não há rosa silk.
 *
 * Isto já foi das duas maneiras. Enquanto uma cor pertenceu a um material, as
 * chips de material limitavam-se a filtrar a paleta; depois a ligação saiu e
 * tudo passou a poder cruzar-se com tudo — o que gerava variantes impossíveis de
 * imprimir. O que voltou não foi o acoplamento 1:N, foi o facto de nem todas as
 * cores existirem em todos os filamentos.
 *
 * Cor e material são obrigatórios; o tamanho não. São eles que definem uma peça
 * imprimível — que tom, e em que filamento.
 *
 * Ao editar, as três secções que multiplicam trocam de face: a matriz dá lugar
 * às variantes que existem, os ficheiros por enviar dão lugar à galeria real, e
 * o preço desaparece — a partir do momento em que há variantes, o preço e o
 * stock são delas, não do produto.
 *
 * NENHUM `useEffect` sincroniza o formulário com o `editing`: a semente é lida
 * uma vez, na montagem, e é o `key` do pai que remonta o modal quando se troca
 * de produto. Um efeito a chamar `setData` competia com o que o admin está a
 * escrever.
 */
export function ProductCreateDialog({
    open,
    onOpenChange,
    editing,
    categories,
    colors,
    materials,
    printers,
    pricing,
    tagSuggestions,
    defaultVatRate,
}: Props) {
    const { data, setData, post, patch, transform, processing, errors, reset } =
        useForm<ProductFormData>({
            name: editing?.product.name ?? '',
            slug: editing?.product.slug ?? '',
            category_id: editing?.product.categoryId ?? null,
            description: editing?.product.description ?? '',
            tags: editing?.product.tags ?? [],
            status: editing?.product.status ?? 'draft',
            featured: editing?.product.featured ?? false,
            vat_rate: editing?.product.vatRate ?? defaultVatRate,
            fulfillment_mode: editing?.product.fulfillmentMode ?? 'in_stock',
            production_time_days: editing?.product.productionTimeDays ?? null,
            allow_backorder: editing?.product.allowBackorder ?? false,
            max_open_production_qty:
                editing?.product.maxOpenProductionQty ?? null,
            images: [],
            variants: {
                color_ids: [],
                material_ids: [],
                sizes: [],
                wholesale_price: '',
                normal_price: '',
                sale_price: '',
            },
        });

    /*
     * "Publicar" também não é um campo — é o que decide, no envio, se o
     * `status` sai como `active` ou fica em `draft`. Mantê-lo fora do
     * formulário poupa ter de o limpar do payload antes de cada post. A editar
     * não existe: aí o estado é um seletor com os três valores à vista, porque
     * arquivar também tem de caber nele.
     */
    const [publish, setPublish] = useState(false);

    /*
     * A ficha de variante aberta, se alguma. O modal troca de face em vez de
     * abrir um segundo por cima: o `useForm` do produto fica montado por baixo
     * com o que o admin já lá escreveu, e volta-se a ele pela seta.
     */
    const [variantTarget, setVariantTarget] = useState<VariantTarget>(null);

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

    const setVariants = (changes: Partial<ProductFormData['variants']>) =>
        setData('variants', { ...data.variants, ...changes });

    const toggle = <T,>(list: readonly T[], value: T): T[] =>
        list.includes(value)
            ? list.filter((item) => item !== value)
            : [...list, value];

    /*
     * A mesma ordem que o ProductService usa para gerar (cor por fora, material
     * no meio, tamanho por dentro), para a pré-visualização não prometer uma
     * coisa e as variantes saírem por outra — e o mesmo filtro: o par que o
     * dono não tem não aparece aqui porque não vai nascer lá.
     */
    const combos = useMemo(() => {
        const sizes = data.variants.sizes.length ? data.variants.sizes : [null];

        return data.variants.color_ids.flatMap((colorId) =>
            data.variants.material_ids
                .filter(
                    (materialId) =>
                        colorsById
                            .get(colorId)
                            ?.materialIds.includes(materialId) ?? false,
                )
                .flatMap((materialId) =>
                    sizes.map((size) => ({
                        key: `${colorId}-${materialId}-${size ?? ''}`,
                        label: [
                            colorsById.get(colorId)?.name,
                            materialsById.get(materialId)?.name,
                            size,
                        ]
                            .filter(
                                (part) => part !== null && part !== undefined,
                            )
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

    /*
     * Quantos pares o catálogo deixou cair. Não é um erro — escolher eixos e
     * receber a intersecção é a funcionalidade —, mas tem de se dizer em voz
     * alta: uma matriz que promete oito variantes e entrega seis, em silêncio,
     * é a mesma surpresa que isto veio resolver, ao contrário.
     */
    const skipped = useMemo(() => {
        const sizeCount = Math.max(data.variants.sizes.length, 1);
        const crossed =
            data.variants.color_ids.length *
            data.variants.material_ids.length *
            sizeCount;

        return crossed - combos.length;
    }, [
        data.variants.color_ids,
        data.variants.material_ids,
        data.variants.sizes,
        combos,
    ]);

    /** Nomes dos filamentos, na ordem em que vieram. */
    const materialNames = (ids: number[]) =>
        ids
            .map((id) => materialsById.get(id)?.name)
            .filter((name): name is string => name !== undefined);

    /*
     * Uma cor só se bloqueia quando é impossível em TODOS os materiais
     * escolhidos.
     *
     * Se escolheste PLA e Silk e o rosa só existe em PLA, tu CONSEGUES fazer a
     * peça em rosa — bloquear o rosa por causa do Silk era recusar uma venda
     * que sabes fazer. O que se faz é gerar só o par que existe e dizê-lo, na
     * nota logo abaixo da grelha.
     */
    const impossibleColorIds = useMemo(() => {
        if (data.variants.material_ids.length === 0) {
            return [];
        }

        return colors
            .filter(
                (color) =>
                    !data.variants.material_ids.some((materialId) =>
                        color.materialIds.includes(materialId),
                    ),
            )
            .map((color) => color.id);
    }, [colors, data.variants.material_ids]);

    const colorReason = (colorId: number) => {
        const color = colorsById.get(colorId);

        if (color === undefined) {
            return '';
        }

        if (color.materialIds.length === 0) {
            return `Ainda não disseste em que filamentos tens ${color.name}.`;
        }

        const names = materialNames(color.materialIds);

        return names.length === 0
            ? `Não tens ${color.name} em nenhum destes filamentos.`
            : `Só tens ${color.name} em ${names.join(', ')}.`;
    };

    /*
     * O que cada cor ESCOLHIDA vai dar, quando não dá tudo.
     *
     * Uma cor escolhida nunca fica esbatida — senão, mudar de material deixava-a
     * presa na selecção sem forma de a tirar —, por isso é aqui que ela tem de
     * dizer o que lhe falta. Sem esta nota, quem escolheu duas cores e dois
     * materiais conta quatro variantes de cabeça e recebe três sem saber qual
     * caiu.
     */
    const partialColors = useMemo(() => {
        if (data.variants.material_ids.length === 0) {
            return [];
        }

        return data.variants.color_ids.flatMap((colorId) => {
            const color = colorsById.get(colorId);

            if (color === undefined) {
                return [];
            }

            const possible = data.variants.material_ids.filter((materialId) =>
                color.materialIds.includes(materialId),
            );

            if (possible.length === data.variants.material_ids.length) {
                return [];
            }

            const names = possible
                .map((id) => materialsById.get(id)?.name)
                .filter((name): name is string => name !== undefined);

            return [
                names.length === 0
                    ? `${color.name}: não tens em nenhum destes filamentos`
                    : `${color.name}: só em ${names.join(' e ')}`,
            ];
        });
    }, [
        data.variants.color_ids,
        data.variants.material_ids,
        colorsById,
        materialsById,
    ]);

    const normalCents = inputToCents(data.variants.normal_price);
    const wholesaleCents = inputToCents(data.variants.wholesale_price);

    /*
     * Cor E material E pelo menos um par possível, a espelhar o
     * ProductService::generateVariants(). O terceiro é o que impede gravar um
     * produto cuja matriz o catálogo esvaziou por inteiro — o servidor recusa-o
     * na mesma, mas depois de o formulário já ter sido submetido.
     *
     * A editar não há matriz nenhuma para validar: as variantes já existem.
     */
    const named = data.name.trim() !== '';
    const canSubmit =
        editing !== null ||
        (named &&
            normalCents > 0 &&
            wholesaleCents > 0 &&
            data.variants.color_ids.length > 0 &&
            data.variants.material_ids.length > 0 &&
            combos.length > 0);

    const messages = errors as Record<string, string>;

    /*
     * Só os eixos: os três preços mostram o erro por baixo do campo, e um
     * apanha-tudo sobre `variants` repetia essa mensagem no fim da secção,
     * longe do campo que a causou.
     */
    const variantsError = Object.entries(messages).find(
        ([key]) =>
            key.startsWith('variants.color_ids') ||
            key.startsWith('variants.material_ids') ||
            key.startsWith('variants.sizes'),
    )?.[1];
    const imagesError = Object.entries(messages).find(([key]) =>
        key.startsWith('images'),
    )?.[1];

    const submit = (status: string) => {
        const options = {
            onSuccess: () => {
                reset();
                setPublish(false);
                onOpenChange(false);
            },
        };

        if (editing !== null) {
            transform((current) => {
                /*
                 * A matriz e os ficheiros são exclusivos da criação — uma chave
                 * que não vai no pedido é uma tabela que o servidor não toca. A
                 * galeria e as variantes de um produto que já existe editam-se
                 * pelos endpoints próprios, não por um update ao produto.
                 */
                const payload: Partial<ProductFormData> & { status: string } = {
                    ...current,
                    status,
                };
                delete payload.images;
                delete payload.variants;

                return payload;
            });

            // `patch` e não `put`: o payload não traz colunas que o formulário
            // não mostra, e um PUT prometia substituir o recurso inteiro.
            patch(update(editing.product.id).url, options);

            return;
        }

        transform((current) => ({ ...current, status }));

        // Sem `forceFormData`: o Inertia deteta os File em `images` sozinho e
        // só então serializa em multipart. Sem fotografias, o pedido continua
        // a ser o JSON de sempre.
        post(store().url, options);
    };

    /*
     * O mesmo `DialogContent` com outro conteúdo lá dentro — o React reconcilia
     * e o modal não pisca. Só existe a editar: a criar ainda não há produto a
     * que a variante possa pertencer, e a matriz de cores × materiais × tamanhos
     * é que faz esse trabalho.
     */
    if (editing !== null && variantTarget !== null) {
        return (
            <Dialog open={open} onOpenChange={onOpenChange}>
                <DialogContent className="max-h-[88vh] gap-0 overflow-y-auto p-0 sm:max-w-2xl">
                    <VariantPanel
                        productId={editing.product.id}
                        productName={editing.product.name}
                        variant={variantTarget === 'new' ? null : variantTarget}
                        suggestedSku={editing.suggestedSku}
                        colors={colors}
                        materials={materials}
                        printers={printers}
                        pricing={pricing}
                        onBack={() => setVariantTarget(null)}
                    />
                </DialogContent>
            </Dialog>
        );
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[88vh] gap-0 overflow-y-auto p-0 sm:max-w-2xl">
                <DialogHeader className="border-b border-border/60 p-6">
                    <DialogTitle>
                        {editing ? 'Editar produto' : 'Novo produto'}
                    </DialogTitle>
                    <DialogDescription>
                        {editing
                            ? 'O preço e o stock vivem nas variantes, não aqui.'
                            : 'Cruza cores, materiais e tamanhos — as variantes são criadas automaticamente.'}
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

                    {/*
                     * Os três preços são o molde que a matriz aplica a todas
                     * as combinações. A editar não aparecem: cada variante já
                     * tem os seus, e um campo aqui em cima prometia escrever
                     * nas trinta de uma vez.
                     *
                     * A gramagem e o tempo de impressão não estão aqui de
                     * propósito — são dados de produção, e vivem na ficha de
                     * cada variante, ao lado do painel que os transforma em
                     * custo.
                     */}
                    {editing === null && (
                        <div className="grid gap-4 sm:grid-cols-3">
                            <div className="grid gap-2">
                                <Label htmlFor="variant-wholesale-price">
                                    Preço de revenda
                                </Label>
                                <div className="relative">
                                    <Input
                                        id="variant-wholesale-price"
                                        type="number"
                                        step="0.01"
                                        min={0}
                                        value={data.variants.wholesale_price}
                                        onChange={(event) =>
                                            setVariants({
                                                wholesale_price:
                                                    event.target.value,
                                            })
                                        }
                                        placeholder="21,00"
                                        className="pr-8 tabular-nums"
                                    />
                                    <span className="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm text-muted-foreground">
                                        €
                                    </span>
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    Só no backoffice — a montra nunca o mostra.
                                </p>
                                <InputError
                                    message={
                                        messages['variants.wholesale_price']
                                    }
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="variant-price">
                                    Preço de venda
                                </Label>
                                <div className="relative">
                                    <Input
                                        id="variant-price"
                                        type="number"
                                        step="0.01"
                                        min={0}
                                        value={data.variants.normal_price}
                                        onChange={(event) =>
                                            setVariants({
                                                normal_price:
                                                    event.target.value,
                                            })
                                        }
                                        placeholder="29,00"
                                        className="pr-8 tabular-nums"
                                    />
                                    <span className="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm text-muted-foreground">
                                        €
                                    </span>
                                </div>
                                <InputError
                                    message={messages['variants.normal_price']}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="variant-sale-price">
                                    Preço em promo
                                </Label>
                                <div className="relative">
                                    <Input
                                        id="variant-sale-price"
                                        type="number"
                                        step="0.01"
                                        min={0}
                                        value={data.variants.sale_price}
                                        onChange={(event) =>
                                            setVariants({
                                                sale_price: event.target.value,
                                            })
                                        }
                                        placeholder="Opcional"
                                        className="pr-8 tabular-nums"
                                    />
                                    <span className="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm text-muted-foreground">
                                        €
                                    </span>
                                </div>
                                <InputError
                                    message={messages['variants.sale_price']}
                                />
                            </div>
                        </div>
                    )}

                    <div className="flex flex-col gap-4 border-t border-border/60 pt-5">
                        <div className="flex items-baseline justify-between gap-3">
                            <Label>Fotografias</Label>
                            <p className="text-xs text-muted-foreground">
                                A primeira é a que aparece nas listagens, no
                                carrinho e nos emails.
                            </p>
                        </div>

                        {editing === null ? (
                            <StagedPhotos
                                files={data.images}
                                onChange={(files) => setData('images', files)}
                                error={imagesError}
                            />
                        ) : (
                            <ProductImages
                                productId={editing.product.id}
                                images={editing.images}
                            />
                        )}
                    </div>

                    <div className="flex flex-col gap-4 border-t border-border/60 pt-5">
                        <div className="flex items-baseline justify-between gap-3">
                            <Label>Variantes</Label>
                            <p className="text-xs text-muted-foreground">
                                Cada combinação fica com stock e preço próprios.
                            </p>
                        </div>

                        {editing === null ? (
                            <>
                                {colors.length === 0 ||
                                materials.length === 0 ? (
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
                                            <ColorSwatchGrid
                                                colors={colors}
                                                multiple
                                                value={data.variants.color_ids}
                                                onChange={(color_ids) =>
                                                    setVariants({ color_ids })
                                                }
                                                disabledIds={impossibleColorIds}
                                                disabledReason={colorReason}
                                            />
                                            {partialColors.length > 0 && (
                                                <p className="text-xs text-muted-foreground">
                                                    {partialColors.join(' · ')}
                                                </p>
                                            )}
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
                                                                material_ids:
                                                                    toggle(
                                                                        data
                                                                            .variants
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
                                        <span className="opacity-70">
                                            (opcional)
                                        </span>
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
                                                ? skipped > 0
                                                    ? 'Não tens nenhuma destas cores nestes filamentos'
                                                    : 'Escolhe pelo menos uma cor e um material'
                                                : combos.length === 1
                                                  ? '1 variante vai ser criada'
                                                  : `${combos.length} variantes vão ser criadas`}
                                        </span>
                                        {combos.length > 0 && skipped > 0 && (
                                            <span className="text-xs text-muted-foreground">
                                                {skipped === 1
                                                    ? '1 combinação que não tens ficou de fora'
                                                    : `${skipped} combinações que não tens ficaram de fora`}
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
                                                    +
                                                    {combos.length -
                                                        PREVIEW_LIMIT}
                                                </span>
                                            )}
                                        </div>
                                    )}

                                    <p className="mt-2.5 text-xs text-muted-foreground">
                                        As referências são geradas a partir do
                                        nome. O stock começa a zero — a primeira
                                        contagem entra pelo registo de stock de
                                        cada variante.
                                    </p>
                                </div>

                                <InputError message={variantsError} />
                            </>
                        ) : (
                            <ExistingVariants
                                editing={editing}
                                onOpenVariant={setVariantTarget}
                            />
                        )}
                    </div>

                    <div className="grid gap-2 border-t border-border/60 pt-5">
                        <Label htmlFor="product-description">
                            Descrição na loja
                        </Label>
                        <RichTextEditor
                            id="product-description"
                            value={data.description}
                            onChange={(html) => setData('description', html)}
                        />
                        <InputError message={errors.description} />
                    </div>

                    {/*
                     * Recolhido por omissão: são os campos que quem despacha
                     * um produto novo nunca abre, mas que a edição precisa
                     * de ter à mão para o modal substituir a página que
                     * havia.
                     */}
                    <details className="rounded-xl border border-border/60">
                        <summary className="cursor-pointer px-4 py-3 text-sm font-medium select-none">
                            Avançado
                            <span className="ml-2 font-normal text-muted-foreground">
                                endereço, etiquetas, IVA, destaque
                            </span>
                        </summary>

                        <div className="flex flex-col gap-4 border-t border-border/60 p-4">
                            <div className="grid gap-2">
                                <Label htmlFor="product-slug">
                                    Endereço na loja
                                </Label>
                                <div className="flex items-center gap-1">
                                    <span className="text-sm text-muted-foreground">
                                        /produtos/
                                    </span>
                                    <Input
                                        id="product-slug"
                                        value={data.slug}
                                        onChange={(event) =>
                                            setData('slug', event.target.value)
                                        }
                                        maxLength={140}
                                        placeholder={
                                            slugify(data.name) ||
                                            'nome-do-produto'
                                        }
                                        className="font-mono"
                                    />
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    Deixa vazio para gerar a partir do nome. Se
                                    lhe mexeres depois de o produto estar
                                    online, os links antigos deixam de
                                    funcionar.
                                </p>
                                <InputError message={errors.slug} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="product-tags">Etiquetas</Label>
                                <TagInput
                                    id="product-tags"
                                    value={data.tags}
                                    onChange={(tags) => setData('tags', tags)}
                                    suggestions={tagSuggestions}
                                />
                                <p className="text-xs text-muted-foreground">
                                    Segundo eixo de organização, ao lado da
                                    categoria: "natal", "presente",
                                    "minimalista".
                                </p>
                                <InputError message={errors.tags} />
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="product-vat">IVA (%)</Label>
                                    <Input
                                        id="product-vat"
                                        type="number"
                                        min={0}
                                        max={100}
                                        value={data.vat_rate}
                                        onChange={(event) =>
                                            setData(
                                                'vat_rate',
                                                Number(event.target.value),
                                            )
                                        }
                                        className="tabular-nums"
                                    />
                                    <InputError message={errors.vat_rate} />
                                </div>

                                {data.fulfillment_mode !== 'in_stock' && (
                                    <div className="grid gap-2">
                                        <Label htmlFor="product-max-open">
                                            Capacidade máx. em produção
                                        </Label>
                                        <Input
                                            id="product-max-open"
                                            type="number"
                                            min={1}
                                            value={
                                                data.max_open_production_qty ??
                                                ''
                                            }
                                            onChange={(event) =>
                                                setData(
                                                    'max_open_production_qty',
                                                    event.target.value === ''
                                                        ? null
                                                        : Number(
                                                              event.target
                                                                  .value,
                                                          ),
                                                )
                                            }
                                            className="tabular-nums"
                                        />
                                        <InputError
                                            message={
                                                errors.max_open_production_qty
                                            }
                                        />
                                    </div>
                                )}
                            </div>

                            <div className="flex flex-wrap items-center gap-6">
                                <label className="flex items-center gap-2 text-sm">
                                    <Checkbox
                                        checked={data.featured}
                                        onCheckedChange={(checked) =>
                                            setData(
                                                'featured',
                                                checked === true,
                                            )
                                        }
                                    />
                                    Destacado
                                </label>

                                {data.fulfillment_mode !== 'in_stock' && (
                                    <label className="flex items-center gap-2 text-sm">
                                        <Checkbox
                                            checked={data.allow_backorder}
                                            onCheckedChange={(checked) =>
                                                setData(
                                                    'allow_backorder',
                                                    checked === true,
                                                )
                                            }
                                        />
                                        Aceitar além da capacidade
                                    </label>
                                )}
                            </div>
                        </div>
                    </details>
                </div>

                <DialogFooter className="items-center gap-3 border-t border-border/60 bg-secondary/30 p-6 sm:justify-between">
                    {editing ? (
                        <div className="flex items-center gap-2">
                            <Label
                                htmlFor="product-status"
                                className="text-sm text-muted-foreground"
                            >
                                Estado
                            </Label>
                            <Select
                                value={data.status}
                                onValueChange={(value) =>
                                    setData('status', value)
                                }
                            >
                                <SelectTrigger
                                    id="product-status"
                                    className="w-40"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {PRODUCT_STATUSES.map((status) => (
                                        <SelectItem
                                            key={status.value}
                                            value={status.value}
                                        >
                                            {status.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    ) : (
                        <label className="flex items-center gap-2 text-sm text-muted-foreground">
                            <Checkbox
                                checked={publish}
                                onCheckedChange={(checked) =>
                                    setPublish(checked === true)
                                }
                            />
                            Publicar na loja online
                        </label>
                    )}

                    <div className="flex gap-2">
                        {editing === null && (
                            <Button
                                type="button"
                                variant="outline"
                                className="rounded-full"
                                disabled={processing || !named}
                                onClick={() => submit('draft')}
                            >
                                Guardar rascunho
                            </Button>
                        )}
                        <Button
                            type="button"
                            className="rounded-full"
                            disabled={processing || !named || !canSubmit}
                            onClick={() =>
                                submit(
                                    editing
                                        ? data.status
                                        : publish
                                          ? 'active'
                                          : 'draft',
                                )
                            }
                        >
                            {editing ? 'Guardar alterações' : 'Criar produto'}
                        </Button>
                    </div>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/** Espelha o Str::slug do servidor o suficiente para servir de pré-visualização. */
function slugify(value: string) {
    return value
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

type StagedProps = {
    files: File[];
    onChange: (files: File[]) => void;
    error?: string;
};

/**
 * As fotografias de um produto que ainda não existe.
 *
 * Não há para onde as enviar — `ImageService::store` precisa de um `Product`, e
 * o índice parcial `product_images_one_primary_per_product` exige que a
 * primeira de um produto seja a principal. Por isso ficam em memória e viajam
 * no mesmo POST que cria o produto: ou nasce com as fotos, ou não nasce.
 *
 * A ordem é a que se vê — a primeira da lista é a que fica principal, e
 * remover a primeira promove a seguinte, exactamente como o servidor faz.
 */
function StagedPhotos({ files, onChange, error }: StagedProps) {
    const fileInput = useRef<HTMLInputElement>(null);
    const [dragging, setDragging] = useState(false);

    /*
     * Um object URL por ficheiro, revogado sempre que a lista muda e no
     * desmonte. Sem o revoke, cada foto largada e retirada deixava o blob
     * agarrado à memória do separador até ele fechar.
     */
    const previews = useMemo(
        () => files.map((file) => URL.createObjectURL(file)),
        [files],
    );

    useEffect(
        () => () => previews.forEach((url) => URL.revokeObjectURL(url)),
        [previews],
    );

    const add = (incoming: FileList | null) => {
        if (!incoming || incoming.length === 0) {
            return;
        }

        onChange([...files, ...Array.from(incoming)].slice(0, MAX_PHOTOS));

        if (fileInput.current) {
            fileInput.current.value = '';
        }
    };

    const full = files.length >= MAX_PHOTOS;

    return (
        <div className="flex flex-col gap-3">
            <label
                onDragOver={(event) => {
                    event.preventDefault();
                    setDragging(true);
                }}
                onDragLeave={() => setDragging(false)}
                onDrop={(event) => {
                    event.preventDefault();
                    setDragging(false);
                    add(event.dataTransfer.files);
                }}
                className={cn(
                    'flex flex-col items-center gap-1.5 rounded-xl border border-dashed p-6 text-center transition-colors',
                    full
                        ? 'cursor-not-allowed border-border opacity-60'
                        : 'cursor-pointer',
                    dragging
                        ? 'border-foreground/40 bg-accent'
                        : 'border-border hover:border-foreground/30',
                )}
            >
                <Upload className="size-5 text-muted-foreground" />
                <span className="text-sm font-medium">
                    {full
                        ? `Máximo de ${MAX_PHOTOS} fotografias`
                        : 'Larga as fotografias aqui ou clica para escolher'}
                </span>
                <span className="text-xs text-muted-foreground">
                    JPG, PNG ou WEBP · até 5 MB cada · o texto alternativo
                    escreve-se depois de o produto existir
                </span>
                <input
                    ref={fileInput}
                    type="file"
                    accept="image/jpeg,image/png,image/webp"
                    multiple
                    disabled={full}
                    className="sr-only"
                    onChange={(event) => add(event.target.files)}
                />
            </label>

            <InputError message={error} />

            {files.length > 0 && (
                <div className="grid grid-cols-3 gap-3 sm:grid-cols-5">
                    {files.map((file, index) => (
                        <div
                            key={`${file.name}-${file.lastModified}-${index}`}
                            className="relative aspect-square overflow-hidden rounded-lg border border-border/60 bg-muted"
                        >
                            <img
                                src={previews[index]}
                                alt=""
                                className="size-full object-cover"
                            />
                            {index === 0 && (
                                <Badge className="absolute top-1 left-1 text-[10px]">
                                    Principal
                                </Badge>
                            )}
                            <button
                                type="button"
                                onClick={() =>
                                    onChange(
                                        files.filter(
                                            (_, position) => position !== index,
                                        ),
                                    )
                                }
                                aria-label={`Retirar ${file.name}`}
                                className="absolute top-1 right-1 grid size-6 place-items-center rounded-full bg-background/90 text-muted-foreground transition-colors hover:text-foreground"
                            >
                                <X className="size-3.5" />
                            </button>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}

type ExistingProps = {
    editing: ProductEditing;
    /** `'new'` abre a ficha em branco; uma linha abre a ficha dessa variante. */
    onOpenVariant: (target: VariantTarget) => void;
};

/**
 * As variantes que já existem.
 *
 * As três acções ficam todas aqui dentro. Criar e editar já foram páginas
 * próprias — o formulário é grande, com preço promocional, revenda, perfil de
 * impressora e painel de custo — mas sair do modal para lhes chegar era perder
 * o produto, a pesquisa e os filtros a cada variante mexida. Agora é o modal
 * que troca de face, e por isso não há `Link` nenhum: nada navega.
 *
 * Arquivar continua a ser a única porta para uma variante sair de circulação,
 * e não existe na ficha dela.
 */
function ExistingVariants({ editing, onOpenVariant }: ExistingProps) {
    const [archiving, setArchiving] = useState<VariantRow | null>(null);

    return (
        <div className="flex flex-col gap-3">
            {editing.variants.length === 0 ? (
                <p className="rounded-xl border border-dashed border-border p-6 text-center text-sm text-muted-foreground">
                    Este produto ainda não tem variantes — cria a primeira para
                    lhe dar preço e stock.
                </p>
            ) : (
                <ul className="divide-y divide-border/60 overflow-hidden rounded-xl border border-border/60">
                    {editing.variants.map((variant) => (
                        <li
                            key={variant.id}
                            className="flex items-center gap-3 px-3 py-2"
                        >
                            <span className="min-w-0 flex-1">
                                <span className="flex flex-wrap items-center gap-2 text-sm font-medium">
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
                                    {variant.material && (
                                        <span>{variant.material.name}</span>
                                    )}
                                    {variant.sizeLabel && (
                                        <span>{variant.sizeLabel}</span>
                                    )}
                                    {!variant.color &&
                                        !variant.material &&
                                        !variant.sizeLabel &&
                                        '—'}
                                </span>
                            </span>

                            <span className="text-right text-sm tabular-nums">
                                <span className="block">
                                    {formatCents(variant.priceCents)}
                                </span>
                                <span
                                    className={cn(
                                        'block text-xs',
                                        variant.lowStock
                                            ? 'text-warning'
                                            : 'text-muted-foreground',
                                    )}
                                >
                                    {variant.availableStock} em stock
                                </span>
                            </span>

                            <span className="flex gap-1">
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => onOpenVariant(variant)}
                                >
                                    Editar
                                </Button>
                                {variant.active && (
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        className="size-8"
                                        onClick={() => setArchiving(variant)}
                                        aria-label={`Arquivar ${variant.sku}`}
                                        title="Arquivar"
                                    >
                                        <Archive className="size-4" />
                                    </Button>
                                )}
                            </span>
                        </li>
                    ))}
                </ul>
            )}

            <div>
                <Button
                    variant="outline"
                    size="sm"
                    onClick={() => onOpenVariant('new')}
                >
                    Nova variante
                </Button>
            </div>

            <ConfirmDialog
                open={archiving !== null}
                onOpenChange={(open) => !open && setArchiving(null)}
                title="Arquivar variante"
                description={
                    <>
                        A variante <strong>{archiving?.sku}</strong> deixa de
                        poder ser vendida. Os movimentos de stock e as
                        encomendas onde aparece mantêm-se.
                    </>
                }
                confirmLabel="Arquivar"
                destructive
                onConfirm={() => {
                    if (archiving) {
                        router.delete(destroyVariant(archiving.id).url, {
                            // O modal fica onde está: o servidor devolve um
                            // `back()`, e sem isto o produto que se estava a
                            // editar fechava a cada variante arquivada.
                            preserveScroll: true,
                            preserveState: true,
                            onFinish: () => setArchiving(null),
                        });
                    }
                }}
            />
        </div>
    );
}
