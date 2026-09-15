import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { ProductSidebar } from '@/components/admin/product-form/sidebar';
import { StagedPhotos } from '@/components/admin/product-form/staged-photos';
import {
    matrixIsPrintable,
    VariantMatrix,
} from '@/components/admin/product-form/variant-matrix';
import { VariantRows } from '@/components/admin/product-form/variant-rows';
import { ProductImages } from '@/components/admin/product-images';
import { RichTextEditor } from '@/components/admin/rich-text-editor';
import type { VariantPricingPreview } from '@/components/admin/variant-form';
import { VariantPanel } from '@/components/admin/variant-panel';
import { VariantProductionTable } from '@/components/admin/variant-production-table';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { inputToCents } from '@/lib/money';
import { productProduction } from '@/lib/production';
import { cn } from '@/lib/utils';
import { index, store, update } from '@/routes/admin/produtos';
import type {
    CategoryOption,
    ColorOption,
    MaterialOption,
    ProductEditing,
    ProductFormData,
    VariantRow,
} from '@/types/catalog';
import { FULFILLMENT_MODES } from '@/types/catalog';
import type { PrinterProfileOption } from '@/types/pricing';

type Props = {
    /** Null cria; um produto edita esse produto. */
    editing: ProductEditing | null;
    categories: CategoryOption[];
    colors: ColorOption[];
    materials: MaterialOption[];
    printers: PrinterProfileOption[];
    tagSuggestions: string[];
    defaultVatRate: number;
    /**
     * O painel de custo da ficha de variante. Recarrega-se sozinho com
     * `only: ['pricing']` enquanto o admin escreve — ver VariantForm.
     */
    pricing: VariantPricingPreview;
    defaultActiveLaborMinutes: number;
};

// O Radix Select não aceita value="" — sentinela para "sem categoria".
const NO_CATEGORY = 'none';

const MODE_HINTS: Record<string, string> = {
    in_stock: 'Já impresso, sai no dia.',
    made_to_order: 'Entra na fila de impressão.',
    custom: 'Feito à medida, sem direito a devolução.',
};

/** A ficha de variante aberta na gaveta: uma da lista, uma nova, ou nenhuma. */
type Drawer = VariantRow | 'new' | null;

/** O título de cada secção da coluna principal. */
function SectionTitle({ children, note }: { children: string; note?: string }) {
    return (
        <div className="mb-4 flex flex-wrap items-baseline justify-between gap-2.5 border-b border-border pb-2.5">
            <h2 className="text-xs font-semibold tracking-[0.1em] text-muted-foreground uppercase">
                {children}
            </h2>
            {note !== undefined && (
                <span className="text-xs text-muted-foreground">{note}</span>
            )}
        </div>
    );
}

export default function ProductForm({
    editing,
    categories,
    colors,
    materials,
    printers,
    tagSuggestions,
    defaultVatRate,
    pricing,
    defaultActiveLaborMinutes,
}: Props) {
    const {
        data,
        setData,
        post,
        patch,
        transform,
        processing,
        errors,
        isDirty,
    } = useForm<ProductFormData>({
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
        max_open_production_qty: editing?.product.maxOpenProductionQty ?? null,
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
     * A ficha da variante abre em gaveta dentro da secção "Variantes", e não
     * num modal por cima: o que se está a editar pertence ao produto que está
     * à frente, e um overlay por cima de um formulário desta altura era perder
     * o fio ao sítio onde se estava.
     */
    const [drawer, setDrawer] = useState<Drawer>(null);

    const messages = errors as Record<string, string>;
    const imagesError = Object.entries(messages).find(([key]) =>
        key.startsWith('images'),
    )?.[1];

    /*
     * A criar, o botão só acende com nome, os dois preços do molde e uma matriz
     * que dê pelo menos uma variante. O servidor recusa na mesma, mas só depois
     * de o formulário ter sido submetido — e este é grande de mais para se
     * descobrir isso no fim.
     *
     * A editar não há nada disto para validar: o produto já existe e as
     * variantes têm ficha própria.
     */
    const named = data.name.trim() !== '';
    const canSubmit =
        editing !== null ||
        (named &&
            inputToCents(data.variants.normal_price) > 0 &&
            inputToCents(data.variants.wholesale_price) > 0 &&
            matrixIsPrintable(data.variants, colors));

    const submit = () => {
        if (editing !== null) {
            transform((current) => {
                /*
                 * A matriz e os ficheiros são exclusivos da criação — uma chave
                 * que não vai no pedido é uma tabela que o servidor não toca. A
                 * galeria e as variantes de um produto que já existe editam-se
                 * pelos endpoints próprios, não por um update ao produto.
                 */
                const payload: Partial<ProductFormData> = { ...current };
                delete payload.images;
                delete payload.variants;

                return payload;
            });

            // `patch` e não `put`: o payload não traz colunas que o formulário
            // não mostra, e um PUT prometia substituir o recurso inteiro.
            patch(update(editing.product.id).url, { preserveScroll: true });

            return;
        }

        // Sem `forceFormData`: o Inertia deteta os File em `images` sozinho e
        // só então serializa em multipart. Sem fotografias, o pedido continua
        // a ser o JSON de sempre.
        post(store().url);
    };

    const production = productProduction(editing?.variants ?? []);

    return (
        <>
            <Head title={editing ? editing.product.name : 'Novo produto'} />

            <div className="flex h-full flex-1 flex-col">
                <div className="flex flex-1 flex-col gap-5 p-6 pb-10">
                    <div className="flex flex-wrap items-end justify-between gap-3.5">
                        <div className="min-w-0">
                            <h1 className="text-2xl font-semibold tracking-tight text-pretty">
                                {editing
                                    ? editing.product.name
                                    : 'Novo produto'}
                            </h1>
                            <p className="mt-1 text-[13px] text-muted-foreground">
                                {editing === null
                                    ? 'Dá-lhe um nome, escolhe como é produzido e cruza as variantes.'
                                    : [
                                          editing.updatedAt === null
                                              ? null
                                              : `Última alteração ${editing.updatedAt}`,
                                          `${editing.variants.length} ${editing.variants.length === 1 ? 'variante' : 'variantes'}`,
                                      ]
                                          .filter((part) => part !== null)
                                          .join(' · ')}
                            </p>
                        </div>
                    </div>

                    <div className="flex flex-wrap items-start gap-5">
                        <div className="flex min-w-0 flex-[1_1_440px] flex-col gap-7">
                            <section>
                                <SectionTitle>Detalhes</SectionTitle>
                                <div className="grid gap-3.5 sm:grid-cols-2">
                                    <div className="grid gap-2 sm:col-span-2">
                                        <Label htmlFor="product-name">
                                            Nome do produto
                                        </Label>
                                        <Input
                                            id="product-name"
                                            value={data.name}
                                            onChange={(event) =>
                                                setData(
                                                    'name',
                                                    event.target.value,
                                                )
                                            }
                                            placeholder="Vaso ondulado"
                                            maxLength={120}
                                            autoFocus={editing === null}
                                        />
                                        <InputError message={errors.name} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="product-category">
                                            Categoria
                                        </Label>
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
                                            <SelectTrigger id="product-category">
                                                <SelectValue placeholder="Sem categoria" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value={NO_CATEGORY}>
                                                    Sem categoria
                                                </SelectItem>
                                                {categories.map((category) => (
                                                    <SelectItem
                                                        key={category.id}
                                                        value={String(
                                                            category.id,
                                                        )}
                                                    >
                                                        {category.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <InputError
                                            message={errors.category_id}
                                        />
                                    </div>

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
                                                    setData(
                                                        'slug',
                                                        event.target.value,
                                                    )
                                                }
                                                maxLength={140}
                                                placeholder="gerado do nome"
                                                className="font-mono"
                                            />
                                        </div>
                                        <InputError message={errors.slug} />
                                    </div>
                                </div>
                            </section>

                            <section>
                                <SectionTitle note="Define o prazo que o cliente vê">
                                    Como é produzido
                                </SectionTitle>
                                <div className="grid gap-2.5 sm:grid-cols-3">
                                    {FULFILLMENT_MODES.map((mode) => {
                                        const active =
                                            data.fulfillment_mode ===
                                            mode.value;

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
                                                    'rounded-xl border p-3.5 text-left transition-colors',
                                                    active
                                                        ? 'border-gold bg-secondary'
                                                        : 'border-border hover:bg-secondary/60',
                                                )}
                                            >
                                                <span className="block text-[13.5px] font-semibold">
                                                    {mode.label}
                                                </span>
                                                <span className="mt-0.5 block text-xs text-pretty text-muted-foreground">
                                                    {MODE_HINTS[mode.value]}
                                                </span>
                                            </button>
                                        );
                                    })}
                                </div>
                                <InputError message={errors.fulfillment_mode} />

                                {data.fulfillment_mode !== 'in_stock' && (
                                    <div className="mt-3.5 flex flex-wrap items-center gap-3">
                                        <Label
                                            htmlFor="production-days"
                                            className="text-[13px] font-normal text-muted-foreground"
                                        >
                                            Prazo de produção
                                        </Label>
                                        <Input
                                            id="production-days"
                                            type="number"
                                            min={0}
                                            max={60}
                                            value={
                                                data.production_time_days ?? ''
                                            }
                                            onChange={(event) =>
                                                setData(
                                                    'production_time_days',
                                                    event.target.value === ''
                                                        ? null
                                                        : Number(
                                                              event.target
                                                                  .value,
                                                          ),
                                                )
                                            }
                                            placeholder="3"
                                            className="h-9 w-20 tabular-nums"
                                        />
                                        <span className="text-[13px] text-muted-foreground">
                                            {data.production_time_days === null
                                                ? 'Sem prazo, o cliente vê "prazo por definir"'
                                                : `O cliente vê "envio em ${data.production_time_days} ${data.production_time_days === 1 ? 'dia' : 'dias'}"`}
                                        </span>
                                        <InputError
                                            message={
                                                errors.production_time_days
                                            }
                                        />
                                    </div>
                                )}
                            </section>

                            <section>
                                <SectionTitle note="A primeira aparece nas listagens e nos emails">
                                    Fotografias
                                </SectionTitle>
                                {editing === null ? (
                                    <StagedPhotos
                                        files={data.images}
                                        onChange={(files) =>
                                            setData('images', files)
                                        }
                                        error={imagesError}
                                    />
                                ) : (
                                    <ProductImages
                                        productId={editing.product.id}
                                        images={editing.images}
                                    />
                                )}
                            </section>

                            <section>
                                <SectionTitle note="O preço e o stock vivem aqui">
                                    Variantes
                                </SectionTitle>

                                {editing === null ? (
                                    <VariantMatrix
                                        value={data.variants}
                                        onChange={(changes) =>
                                            setData('variants', {
                                                ...data.variants,
                                                ...changes,
                                            })
                                        }
                                        colors={colors}
                                        materials={materials}
                                        messages={messages}
                                    />
                                ) : (
                                    <>
                                        <VariantRows
                                            variants={editing.variants}
                                            openId={
                                                drawer === null
                                                    ? null
                                                    : drawer === 'new'
                                                      ? 'new'
                                                      : drawer.id
                                            }
                                            onOpenVariant={(variant) =>
                                                setDrawer(variant ?? 'new')
                                            }
                                        />

                                        {drawer !== null && (
                                            <div className="mt-4 overflow-hidden rounded-xl border border-gold bg-card">
                                                <VariantPanel
                                                    productId={
                                                        editing.product.id
                                                    }
                                                    productName={
                                                        editing.product.name
                                                    }
                                                    variant={
                                                        drawer === 'new'
                                                            ? null
                                                            : drawer
                                                    }
                                                    suggestedSku={
                                                        editing.suggestedSku
                                                    }
                                                    production={production}
                                                    colors={colors}
                                                    materials={materials}
                                                    printers={printers}
                                                    pricing={pricing}
                                                    defaultActiveLaborMinutes={
                                                        defaultActiveLaborMinutes
                                                    }
                                                    onClose={() =>
                                                        setDrawer(null)
                                                    }
                                                />
                                            </div>
                                        )}

                                        {drawer === null && (
                                            <div className="mt-3.5 flex flex-wrap items-center gap-3">
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="sm"
                                                    className="rounded-full"
                                                    onClick={() =>
                                                        setDrawer('new')
                                                    }
                                                >
                                                    Nova variante
                                                </Button>
                                                {editing.variants.length ===
                                                    0 && (
                                                    <span className="text-[13px] text-muted-foreground">
                                                        Sem variantes, o produto
                                                        não pode ficar ativo.
                                                    </span>
                                                )}
                                            </div>
                                        )}
                                    </>
                                )}
                            </section>

                            {editing !== null && (
                                <section>
                                    <SectionTitle note="Igual para todas as variantes">
                                        Impressão
                                    </SectionTitle>
                                    <VariantProductionTable editing={editing} />
                                </section>
                            )}

                            <section>
                                <SectionTitle>Descrição na loja</SectionTitle>
                                <RichTextEditor
                                    id="product-description"
                                    value={data.description}
                                    onChange={(html) =>
                                        setData('description', html)
                                    }
                                />
                                <InputError message={errors.description} />
                            </section>

                            {/*
                             * Recolhido por omissão: são os campos que ninguém
                             * abre para despachar um produto novo, mas que têm
                             * de estar à mão de quem afina um que já vende.
                             */}
                            <details className="rounded-xl border border-border/60">
                                <summary className="cursor-pointer px-4 py-3 text-sm font-medium select-none">
                                    Avançado
                                    <span className="ml-2 font-normal text-muted-foreground">
                                        IVA, capacidade em produção
                                    </span>
                                </summary>

                                <div className="flex flex-col gap-4 border-t border-border/60 p-4">
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <div className="grid gap-2">
                                            <Label htmlFor="product-vat">
                                                IVA (%)
                                            </Label>
                                            <Input
                                                id="product-vat"
                                                type="number"
                                                min={0}
                                                max={100}
                                                value={data.vat_rate}
                                                onChange={(event) =>
                                                    setData(
                                                        'vat_rate',
                                                        Number(
                                                            event.target.value,
                                                        ),
                                                    )
                                                }
                                                className="tabular-nums"
                                            />
                                            <InputError
                                                message={errors.vat_rate}
                                            />
                                        </div>

                                        {data.fulfillment_mode !==
                                            'in_stock' && (
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
                                                            event.target
                                                                .value === ''
                                                                ? null
                                                                : Number(
                                                                      event
                                                                          .target
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
                            </details>
                        </div>

                        <ProductSidebar
                            data={data}
                            setData={setData}
                            errors={errors}
                            editing={editing}
                            tagSuggestions={tagSuggestions}
                        />
                    </div>
                </div>

                {/*
                 * A barra acompanha o scroll até ao fim: um formulário com esta
                 * altura não pode obrigar a descer seis secções para gravar uma
                 * correcção feita na primeira.
                 */}
                <div className="sticky bottom-0 flex flex-wrap items-center gap-3 border-t border-border bg-card/95 px-6 py-3 backdrop-blur">
                    <span
                        className={cn(
                            'size-1.5 flex-none rounded-full',
                            isDirty ? 'bg-gold' : 'bg-border',
                        )}
                        aria-hidden
                    />
                    <span className="min-w-30 flex-1 text-[13px] text-muted-foreground">
                        {editing === null
                            ? named
                                ? 'Pronto a criar.'
                                : 'Falta o nome do produto.'
                            : isDirty
                              ? 'Alterações por guardar.'
                              : 'Tudo guardado.'}
                    </span>

                    <Button asChild variant="ghost" className="rounded-full">
                        <Link href={index()}>
                            {editing === null ? 'Cancelar' : 'Voltar'}
                        </Link>
                    </Button>
                    <Button
                        type="button"
                        className="rounded-full"
                        disabled={processing || !canSubmit}
                        onClick={submit}
                    >
                        {processing && <Spinner />}
                        {editing === null
                            ? 'Criar produto'
                            : 'Guardar alterações'}
                    </Button>
                </div>
            </div>
        </>
    );
}

ProductForm.layout = {
    breadcrumbs: [
        { title: 'Backoffice', href: '/admin' },
        { title: 'Produtos', href: index() },
        { title: 'Produto', href: '#' },
    ],
};
