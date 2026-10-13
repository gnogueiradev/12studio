import { Head, Link, useForm } from '@inertiajs/react';
import { AdminTable } from '@/components/admin/admin-table';
import type { Column } from '@/components/admin/admin-table';
import { EtsyNav } from '@/components/admin/etsy/etsy-nav';
import { TaxonomyPicker } from '@/components/admin/etsy/taxonomy-picker';
import type { TaxonomyNode } from '@/components/admin/etsy/taxonomy-picker';
import { PageHeader } from '@/components/admin/page-header';
import { Panel } from '@/components/admin/panel';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
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
import { formatCents, formatPercentBp } from '@/lib/money';
import { cn } from '@/lib/utils';
import { index as listings } from '@/routes/admin/etsy/anuncios';
import { definicoes, index } from '@/routes/admin/etsy/precos';
import { show as productListing } from '@/routes/admin/produtos/etsy';
import { priceSourceLabel, whenMadeLabel, whoMadeLabel } from '@/types/etsy';

/** Um lado da comparação: o que um preço rende, depois de tudo tirado. */
type Side = {
    priceCents: number;
    exVatCents: number;
    feesCents: number;
    netCents: number;
    /** Null sem peso, tempo e material na variante: não há custo que calcular. */
    costCents: number | null;
    profitCents: number | null;
    marginBp: number | null;
};

type Row = {
    variantId: number;
    productId: number;
    product: string;
    sku: string;
    label: string;
    priceSource: string;
    site: Side;
    etsy: Side;
};

type Settings = {
    markupPercent: string;
    feePercent: string;
    feeFixed: string;
    mtoQuantity: number;
    stockBuffer: number;
    defaultTaxonomyId: number | null;
    whoMade: string;
    whenMade: string;
};

type Props = {
    rows: Row[];
    settings: Settings;
    options: { whoMade: string[]; whenMade: string[] };
    taxonomyResults?: TaxonomyNode[];
};

/**
 * Etsy → Preços: o que cada variante rende no site e o que renderia na Etsy,
 * lado a lado. É daqui que se decide a política de preço — o mesmo do site,
 * uma margem por cima, ou um preço próprio em cada produto.
 */
export default function EtsyPricing({
    rows,
    settings,
    options,
    taxonomyResults,
}: Props) {
    return (
        <>
            <Head title="Preços na Etsy" />
            <div className="flex h-full w-full max-w-[1200px] flex-1 flex-col p-6 pb-10">
                <PageHeader
                    title="Preços na Etsy"
                    description="O que sobra de cada venda no site e na Etsy, e as escolhas que mexem nessa conta."
                />
                <EtsyNav current="precos" />

                <div className="mt-6 grid gap-6">
                    <SettingsForm
                        // Remonta com o que ficou gravado.
                        key={JSON.stringify(settings)}
                        settings={settings}
                        options={options}
                        taxonomyResults={taxonomyResults}
                    />
                    <Margins rows={rows} />
                </div>
            </div>
        </>
    );
}

function SettingsForm({
    settings,
    options,
    taxonomyResults,
}: {
    settings: Settings;
    options: Props['options'];
    taxonomyResults: TaxonomyNode[] | undefined;
}) {
    const { data, setData, patch, processing, errors, isDirty } = useForm({
        markup_percent: settings.markupPercent,
        fee_percent: settings.feePercent,
        fee_fixed: settings.feeFixed,
        mto_quantity: settings.mtoQuantity,
        stock_buffer: settings.stockBuffer,
        default_taxonomy_id: settings.defaultTaxonomyId,
        who_made: settings.whoMade,
        when_made: settings.whenMade,
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        patch(definicoes().url, { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="grid gap-6 lg:grid-cols-2">
            <Panel
                title="Preço e taxas"
                description="Ficam guardados aqui. Na Etsy, um preço só muda quando o anúncio for enviado outra vez."
            >
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field
                        id="markup"
                        label="Margem sobre o preço do site (%)"
                        hint="0 = a Etsy leva o preço do site. Um preço próprio numa variante passa à frente disto."
                        error={errors.markup_percent}
                    >
                        <Input
                            id="markup"
                            inputMode="decimal"
                            value={data.markup_percent}
                            onChange={(event) =>
                                setData('markup_percent', event.target.value)
                            }
                        />
                    </Field>
                    <Field
                        id="fee-percent"
                        label="Taxas da Etsy (%)"
                        hint="Transação mais processamento do pagamento, sobre o que o comprador paga."
                        error={errors.fee_percent}
                    >
                        <Input
                            id="fee-percent"
                            inputMode="decimal"
                            value={data.fee_percent}
                            onChange={(event) =>
                                setData('fee_percent', event.target.value)
                            }
                        />
                    </Field>
                    <Field
                        id="fee-fixed"
                        label="Taxa fixa por venda (€)"
                        hint="A parte fixa do pagamento mais a taxa de publicação."
                        error={errors.fee_fixed}
                    >
                        <Input
                            id="fee-fixed"
                            inputMode="decimal"
                            value={data.fee_fixed}
                            onChange={(event) =>
                                setData('fee_fixed', event.target.value)
                            }
                        />
                    </Field>
                    <Field
                        id="stock-buffer"
                        label="Folga de stock"
                        hint="Peças em stock que ficam por anunciar na Etsy, de reserva."
                        error={errors.stock_buffer}
                    >
                        <Input
                            id="stock-buffer"
                            type="number"
                            min={0}
                            value={data.stock_buffer}
                            onChange={(event) =>
                                setData(
                                    'stock_buffer',
                                    Number(event.target.value),
                                )
                            }
                        />
                    </Field>
                </div>
                <p className="mt-4 text-xs text-pretty text-muted-foreground">
                    As taxas são uma estimativa para fazer contas — acerta-as
                    com o que vires na conta da loja. Quando houver vendas, a
                    margem de cada encomenda passa a usar as taxas reais.
                </p>
            </Panel>

            <Panel
                title="Um anúncio novo, por omissão"
                description="O que leva um produto que não escolheu outra coisa no seu anúncio."
            >
                <div className="grid gap-4">
                    <div className="grid gap-1.5">
                        <Label htmlFor="default-taxonomy">Categoria</Label>
                        <TaxonomyPicker
                            id="default-taxonomy"
                            value={data.default_taxonomy_id}
                            onChange={(taxonomyId) =>
                                setData('default_taxonomy_id', taxonomyId)
                            }
                            results={taxonomyResults}
                        />
                        <InputError message={errors.default_taxonomy_id} />
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            id="who-made"
                            label="Quem fez"
                            error={errors.who_made}
                        >
                            <Select
                                value={data.who_made}
                                onValueChange={(value) =>
                                    setData('who_made', value)
                                }
                            >
                                <SelectTrigger id="who-made" className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {options.whoMade.map((value) => (
                                        <SelectItem key={value} value={value}>
                                            {whoMadeLabel(value)}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                        <Field
                            id="when-made"
                            label="Quando foi feito"
                            error={errors.when_made}
                        >
                            <Select
                                value={data.when_made}
                                onValueChange={(value) =>
                                    setData('when_made', value)
                                }
                            >
                                <SelectTrigger
                                    id="when-made"
                                    className="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {options.whenMade.map((value) => (
                                        <SelectItem key={value} value={value}>
                                            {whenMadeLabel(value)}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                    </div>

                    <Field
                        id="mto-quantity"
                        label="Quantidade anunciada (feito por encomenda)"
                        hint="Um produto feito por encomenda não tem prateleira, mas a Etsy quer um número."
                        error={errors.mto_quantity}
                    >
                        <Input
                            id="mto-quantity"
                            type="number"
                            min={1}
                            className="max-w-32"
                            value={data.mto_quantity}
                            onChange={(event) =>
                                setData(
                                    'mto_quantity',
                                    Number(event.target.value),
                                )
                            }
                        />
                    </Field>
                </div>
            </Panel>

            <div className="flex items-center gap-3 lg:col-span-2">
                <Button type="submit" disabled={processing || !isDirty}>
                    {processing && <Spinner />}
                    Guardar
                </Button>
                {isDirty && (
                    <span className="text-sm text-warning">
                        Alterações por guardar.
                    </span>
                )}
            </div>
        </form>
    );
}

function Field({
    id,
    label,
    hint,
    error,
    children,
}: {
    id: string;
    label: string;
    hint?: string;
    error?: string;
    children: React.ReactNode;
}) {
    return (
        <div className="grid content-start gap-1.5">
            <Label htmlFor={id}>{label}</Label>
            {children}
            {hint && (
                <p className="text-xs text-pretty text-muted-foreground">
                    {hint}
                </p>
            )}
            <InputError message={error} />
        </div>
    );
}

/** O lucro e a margem de um lado, ou a razão de não os haver. */
function Profit({ side }: { side: Side }) {
    if (side.profitCents === null) {
        return <span className="text-muted-foreground">sem custo</span>;
    }

    return (
        <span
            className={cn(
                'tabular-nums',
                side.profitCents < 0 && 'font-semibold text-destructive',
            )}
        >
            {formatCents(side.profitCents)}
            {side.marginBp !== null && (
                <span className="ml-1.5 text-xs text-muted-foreground">
                    {formatPercentBp(side.marginBp)}
                </span>
            )}
        </span>
    );
}

function Margins({ rows }: { rows: Row[] }) {
    const columns: Column<Row>[] = [
        {
            key: 'variant',
            header: 'Variante',
            cell: (row) => (
                <div className="min-w-0">
                    <Link
                        href={productListing(row.productId)}
                        className="font-medium underline-offset-4 hover:underline"
                    >
                        {row.product}
                    </Link>
                    <div className="text-xs text-muted-foreground">
                        {row.sku}
                        {row.label !== '' && ` · ${row.label}`}
                    </div>
                </div>
            ),
        },
        {
            key: 'site-price',
            header: 'Site',
            className: 'text-right',
            cell: (row) => (
                <span className="tabular-nums">
                    {formatCents(row.site.priceCents)}
                </span>
            ),
        },
        {
            key: 'site-profit',
            header: 'Lucro no site',
            className: 'text-right',
            cell: (row) => <Profit side={row.site} />,
        },
        {
            key: 'etsy-price',
            header: 'Etsy',
            className: 'text-right',
            cell: (row) => (
                <div>
                    <span className="tabular-nums">
                        {formatCents(row.etsy.priceCents)}
                    </span>
                    <div className="text-xs text-muted-foreground">
                        {priceSourceLabel(row.priceSource)}
                    </div>
                </div>
            ),
        },
        {
            key: 'etsy-fees',
            header: 'Taxas',
            className: 'text-right',
            cell: (row) => (
                <span className="text-muted-foreground tabular-nums">
                    −{formatCents(row.etsy.feesCents)}
                </span>
            ),
        },
        {
            key: 'etsy-net',
            header: 'Sobra',
            className: 'text-right',
            cell: (row) => (
                <span className="tabular-nums">
                    {formatCents(row.etsy.netCents)}
                </span>
            ),
        },
        {
            key: 'etsy-profit',
            header: 'Lucro na Etsy',
            className: 'text-right',
            cell: (row) => <Profit side={row.etsy} />,
        },
    ];

    return (
        <section>
            <h2 className="text-sm font-semibold">Margens por variante</h2>
            <p className="mt-1 mb-3 max-w-3xl text-sm text-pretty text-muted-foreground">
                Os preços levam IVA; o lucro é o que fica depois de tirado o
                IVA, as taxas e o custo de produção. “Sobra” é o que a Etsy
                deixa antes do custo. O preço de cada variante acerta-se no
                anúncio do produto.
            </p>
            <AdminTable
                columns={columns}
                rows={rows}
                rowKey={(row) => row.variantId}
                empty="Ainda não há variantes ativas para comparar."
            />
        </section>
    );
}

EtsyPricing.layout = {
    breadcrumbs: [
        { title: 'Backoffice', href: '/admin' },
        { title: 'Etsy', href: listings() },
        { title: 'Preços', href: index() },
    ],
};
