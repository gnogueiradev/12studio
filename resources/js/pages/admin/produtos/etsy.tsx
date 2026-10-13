import { Head, Link, router, useForm } from '@inertiajs/react';
import { ExternalLink, ShieldAlert, TriangleAlert } from 'lucide-react';
import { useRef, useState } from 'react';
import { ConfirmDialog } from '@/components/admin/confirm-dialog';
import { TaxonomyPicker } from '@/components/admin/etsy/taxonomy-picker';
import type { TaxonomyNode } from '@/components/admin/etsy/taxonomy-picker';
import { PageHeader } from '@/components/admin/page-header';
import { Panel } from '@/components/admin/panel';
import { TagInput } from '@/components/admin/tag-input';
import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
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
import { Textarea } from '@/components/ui/textarea';
import { centsToInput, formatCents } from '@/lib/money';
import { cn } from '@/lib/utils';
import { edit as editProduct, index } from '@/routes/admin/produtos';
import {
    apagar,
    desativar,
    enviar,
    guardar,
    publicar,
    video as uploadVideo,
} from '@/routes/admin/produtos/etsy';
import { remover as removeVideo } from '@/routes/admin/produtos/etsy/video';
import {
    deliveryLabel,
    listingStateLabel,
    priceSourceLabel,
    PROCESSING_STATES,
    whenMadeLabel,
    whoMadeLabel,
} from '@/types/etsy';

/** O conteúdo de um anúncio: o próprio do produto, ou o que valeria hoje. */
type Content = {
    title: string;
    description: string;
    tags: string[];
    materials: string[];
    taxonomyId: number | null;
    whoMade: string | null;
    whenMade: string | null;
    shippingProfileId: number | null;
    returnPolicyId: number | null;
    readinessStateId: number | null;
    shopSectionId: number | null;
};

type VariantRow = {
    id: number;
    sku: string;
    label: string;
    sitePriceCents: number;
    /** O preço próprio na Etsy, se a variante tiver um. */
    etsyPriceCents: number | null;
    /** O que iria hoje: o próprio, o do site com a margem, ou o do site. */
    effectivePriceCents: number;
    priceSource: string;
    quantity: number;
    enabled: boolean;
};

type ImageRow = {
    id: number;
    url: string;
    alt: string | null;
    /** Fica no 12studio, mas não vai para a Etsy. */
    excluded: boolean;
    onEtsy: boolean;
};

type Listing = {
    onEtsy: boolean;
    etsyListingId: number | null;
    state: string | null;
    url: string | null;
    lastPushedAt: string | null;
    lastError: string | null;
    /** Há alguma coisa por enviar desde o último envio? */
    pending: boolean;
    videoUrl: string | null;
    videoOnEtsy: boolean;
    /** O 12studio mantém-no em dia sozinho (stock, preços, textos)? */
    syncEnabled: boolean;
    /** Foi a sincronização que o tirou de venda (sem stock, arquivado)? */
    autoDeactivated: boolean;
    imagesOnEtsy: number;
    /** O que impede pô-lo à venda, já por palavras. Null = pode. */
    publishBlocker: string | null;
};

type Difference = { field: string; onEtsy: string; wouldSend: string };

type ProcessingOption = {
    id: number;
    state: string;
    min: number | null;
    max: number | null;
    unit: string | null;
};

type ReturnPolicyOption = {
    id: number;
    acceptsReturns: boolean;
    acceptsExchanges: boolean;
    deadline: number | null;
};

type Props = {
    product: { id: number; name: string; status: string };
    connected: boolean;
    /** O que a Etsy respondeu quando não deixou ler a loja. */
    error: string | null;
    listing: Listing;
    own: Content;
    effective: Content;
    variants: VariantRow[];
    images: ImageRow[];
    blockers: string[];
    warnings: string[];
    /** Null enquanto não se pedir a comparação (custa uma leitura à Etsy). */
    differences: Difference[] | null;
    options: {
        shippingProfiles: { id: number; title: string }[];
        processingProfiles: ProcessingOption[];
        returnPolicies: ReturnPolicyOption[];
        sections: { id: number; title: string }[];
        whoMade: string[];
        whenMade: string[];
    };
    limits: { titleMax: number; tagsMax: number; tagLengthMax: number };
    taxonomyResults?: TaxonomyNode[];
};

/** O valor que o Select usa para "o predefinido" (o Radix não aceita ''). */
const NONE = 'none';

const dateTime = new Intl.DateTimeFormat('pt-PT', {
    dateStyle: 'medium',
    timeStyle: 'short',
});

/**
 * O anúncio de um produto na Etsy. Dois passos, de propósito: “Guardar” fica
 * no 12studio; só “Enviar para a Etsy” escreve lá — e antes disso o ecrã já
 * disse o que vai e o que falta.
 */
export default function ProductEtsyListing(props: Props) {
    const { product, connected, error, listing, blockers, warnings } = props;

    return (
        <>
            <Head title={`Etsy — ${product.name}`} />
            <div className="flex h-full w-full max-w-[1100px] flex-1 flex-col p-6 pb-10">
                <PageHeader
                    title={`Anúncio na Etsy — ${product.name}`}
                    description="O que este produto diz e custa na Etsy. O que ficar vazio vem do produto."
                >
                    <Button variant="outline" asChild>
                        <Link href={editProduct(product.id)}>
                            Voltar ao produto
                        </Link>
                    </Button>
                </PageHeader>

                {!connected && (
                    <Alert className="mt-5 max-w-3xl">
                        <ShieldAlert />
                        <AlertTitle>Ainda não há loja ligada</AlertTitle>
                        <AlertDescription>
                            Podes preparar o anúncio à mesma; para o enviar,
                            liga a loja em Definições → Etsy.
                        </AlertDescription>
                    </Alert>
                )}

                {error !== null && (
                    <Alert variant="destructive" className="mt-5 max-w-3xl">
                        <ShieldAlert />
                        <AlertTitle>Não foi possível ler a loja</AlertTitle>
                        <AlertDescription>{error}</AlertDescription>
                    </Alert>
                )}

                <div className="mt-6 grid gap-6">
                    <Status {...props} />

                    {listing.onEtsy && (
                        <Sale
                            product={product}
                            connected={connected}
                            listing={listing}
                        />
                    )}

                    {blockers.length > 0 && (
                        <Alert variant="destructive">
                            <ShieldAlert />
                            <AlertTitle>
                                Falta isto para o anúncio poder ir
                            </AlertTitle>
                            <AlertDescription>
                                <ul className="list-disc pl-4">
                                    {blockers.map((blocker) => (
                                        <li key={blocker}>{blocker}</li>
                                    ))}
                                </ul>
                            </AlertDescription>
                        </Alert>
                    )}

                    {warnings.length > 0 && (
                        <Alert>
                            <TriangleAlert />
                            <AlertTitle>Convém saber</AlertTitle>
                            <AlertDescription>
                                <ul className="list-disc pl-4">
                                    {warnings.map((warning) => (
                                        <li key={warning}>{warning}</li>
                                    ))}
                                </ul>
                            </AlertDescription>
                        </Alert>
                    )}

                    <ContentForm
                        // Remonta com o que ficou gravado.
                        key={JSON.stringify([
                            props.own,
                            props.variants,
                            props.images,
                            listing.syncEnabled,
                        ])}
                        {...props}
                    />

                    <Video productId={product.id} listing={listing} />
                </div>
            </div>
        </>
    );
}

/**
 * Onde está o anúncio, e o botão que o manda. Enviar por cima de um anúncio
 * à venda, ou de um que já existia na Etsy, pergunta primeiro.
 */
function Status({ product, connected, listing, blockers, differences }: Props) {
    const [confirming, setConfirming] = useState(false);
    const [sending, setSending] = useState(false);
    const [comparing, setComparing] = useState(false);

    const live = listing.state === 'active';
    // Feito à mão na Etsy e nunca enviado daqui: o primeiro envio passa-lhe
    // por cima.
    const adopted = listing.onEtsy && listing.lastPushedAt === null;

    const send = () => {
        setSending(true);
        router.post(
            enviar(product.id).url,
            {},
            {
                preserveScroll: true,
                onFinish: () => {
                    setSending(false);
                    setConfirming(false);
                },
            },
        );
    };

    const compare = () => {
        setComparing(true);
        router.reload({
            only: ['differences', 'error'],
            data: { comparar: 1 },
            onFinish: () => setComparing(false),
        });
    };

    return (
        <Panel
            title="Na Etsy"
            aside={
                listing.url !== null && (
                    <a
                        href={listing.url}
                        target="_blank"
                        rel="noreferrer"
                        className="inline-flex items-center gap-1 text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                    >
                        Ver na Etsy <ExternalLink className="size-3" />
                    </a>
                )
            }
        >
            <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-3">
                <div>
                    <dt className="text-xs text-muted-foreground">Estado</dt>
                    <dd className="font-medium">
                        {listing.onEtsy
                            ? listingStateLabel(listing.state)
                            : 'Ainda não existe na Etsy'}
                    </dd>
                </div>
                <div>
                    <dt className="text-xs text-muted-foreground">Anúncio</dt>
                    <dd className="tabular-nums">
                        {listing.etsyListingId ?? '—'}
                    </dd>
                </div>
                <div>
                    <dt className="text-xs text-muted-foreground">
                        Último envio
                    </dt>
                    <dd>
                        {listing.lastPushedAt === null
                            ? 'Nunca'
                            : dateTime.format(new Date(listing.lastPushedAt))}
                    </dd>
                </div>
            </dl>

            {listing.lastError !== null && (
                <p
                    role="alert"
                    className="mt-3 rounded-lg border border-destructive/40 px-3 py-2 text-sm text-destructive"
                >
                    No último envio: {listing.lastError}
                </p>
            )}

            {adopted && (
                <p className="mt-3 text-sm text-pretty text-warning">
                    Este anúncio já existia na Etsy. O primeiro envio substitui
                    os preços, as quantidades e as fotografias que lá estão
                    pelos do produto — compara primeiro.
                </p>
            )}

            <div className="mt-4 flex flex-wrap items-center gap-3">
                <Button
                    type="button"
                    disabled={!connected || blockers.length > 0 || sending}
                    onClick={() =>
                        live || adopted ? setConfirming(true) : send()
                    }
                >
                    {sending && <Spinner />}
                    {listing.onEtsy
                        ? 'Enviar para a Etsy'
                        : 'Criar rascunho na Etsy'}
                </Button>
                {listing.onEtsy && (
                    <Button
                        type="button"
                        variant="outline"
                        disabled={!connected || comparing}
                        onClick={compare}
                    >
                        {comparing && <Spinner />}
                        Comparar com o que lá está
                    </Button>
                )}
                <span className="text-sm text-muted-foreground">
                    {!listing.onEtsy
                        ? 'Um rascunho não fica à venda nem custa nada.'
                        : listing.pending
                          ? 'Há alterações por enviar.'
                          : 'Está tudo enviado.'}
                </span>
            </div>

            {differences !== null && (
                <div className="mt-4">
                    {differences.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            O que iria é igual ao que está na Etsy.
                        </p>
                    ) : (
                        <div className="overflow-x-auto rounded-lg border border-border/60">
                            <table className="w-full text-[13px]">
                                <thead>
                                    <tr className="border-b border-border/60 text-left text-xs text-muted-foreground">
                                        <th className="px-3 py-2 font-medium">
                                            O quê
                                        </th>
                                        <th className="px-3 py-2 font-medium">
                                            Na Etsy
                                        </th>
                                        <th className="px-3 py-2 font-medium">
                                            Iria
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {differences.map((difference) => (
                                        <tr
                                            key={difference.field}
                                            className="border-b border-border/40 align-top last:border-0"
                                        >
                                            <td className="px-3 py-2 font-medium">
                                                {difference.field}
                                            </td>
                                            <td className="max-w-xs px-3 py-2 break-words whitespace-pre-line text-muted-foreground">
                                                {difference.onEtsy || '—'}
                                            </td>
                                            <td className="max-w-xs px-3 py-2 break-words whitespace-pre-line">
                                                {difference.wouldSend || '—'}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            )}

            <ConfirmDialog
                open={confirming}
                onOpenChange={setConfirming}
                title={
                    live
                        ? 'Enviar para um anúncio à venda'
                        : 'Primeiro envio para este anúncio'
                }
                description={
                    live
                        ? 'O anúncio está à venda: o título, os preços, as quantidades e as fotografias mudam para os compradores assim que carregares.'
                        : 'O anúncio já existia na Etsy. Os preços, as quantidades e as fotografias que lá estão são substituídos pelos do produto.'
                }
                confirmLabel="Enviar para a Etsy"
                processing={sending}
                onConfirm={send}
            />
        </Panel>
    );
}

type SaleAction = 'publicar' | 'desativar' | 'apagar';

/**
 * Pôr à venda, tirar de venda, apagar. As três perguntam antes: publicar
 * custa a taxa da Etsy, e apagar, lá, não tem volta.
 */
function Sale({
    product,
    connected,
    listing,
}: Pick<Props, 'product' | 'connected' | 'listing'>) {
    const [asking, setAsking] = useState<SaleAction | null>(null);
    const [busy, setBusy] = useState(false);

    const live = listing.state === 'active';
    // Repor um esgotado ou um expirado é renovar, e renovar paga-se.
    const renewal = listing.state === 'sold_out' || listing.state === 'expired';

    const run = () => {
        if (asking === null) {
            return;
        }

        const options = {
            preserveScroll: true,
            onFinish: () => {
                setBusy(false);
                setAsking(null);
            },
        };

        setBusy(true);

        if (asking === 'apagar') {
            router.delete(apagar(product.id).url, options);
        } else {
            router.post(
                (asking === 'publicar' ? publicar : desativar)(product.id).url,
                {},
                options,
            );
        }
    };

    const dialogs: Record<
        SaleAction,
        { title: string; description: string; confirm: string }
    > = {
        publicar: {
            title: renewal ? 'Renovar o anúncio' : 'Pôr à venda na Etsy',
            description: renewal
                ? 'O anúncio volta a estar à venda e a Etsy cobra a taxa de publicação outra vez (é uma renovação).'
                : 'O anúncio passa a estar à vista dos compradores e a Etsy cobra a taxa de publicação. A partir daí o stock e os preços seguem para lá sozinhos.',
            confirm: renewal ? 'Renovar e pagar a taxa' : 'Publicar',
        },
        desativar: {
            title: 'Tirar de venda',
            description:
                'Os compradores deixam de ver o anúncio. Continua a existir na Etsy, com as suas estatísticas, e só volta quando o publicares outra vez — a sincronização não o liga sozinha.',
            confirm: 'Tirar de venda',
        },
        apagar: {
            title: 'Apagar o anúncio da Etsy',
            description:
                'Na Etsy não se recupera: vão-se as estatísticas, os favoritos e o endereço do anúncio. O produto não muda, e o que preparaste aqui (texto, preços) fica para um anúncio novo.',
            confirm: 'Apagar da Etsy',
        },
    };

    return (
        <Panel
            title="À venda"
            description="Publicar é o único passo que custa dinheiro. Depois de publicado, o 12studio mantém o stock e os preços em dia."
        >
            {listing.autoDeactivated && (
                <p className="mb-3 text-sm text-pretty text-warning">
                    O 12studio tirou este anúncio de venda — não havia stock, ou
                    o produto foi arquivado. Volta sozinho quando houver o que
                    vender.
                </p>
            )}

            {!listing.syncEnabled && (
                <p className="mb-3 text-sm text-pretty text-muted-foreground">
                    A sincronização deste anúncio está desligada: o stock e os
                    preços só seguem quando carregares em “Enviar para a Etsy”.
                </p>
            )}

            <div className="flex flex-wrap items-center gap-3">
                {live ? (
                    <Button
                        type="button"
                        variant="outline"
                        disabled={!connected || busy}
                        onClick={() => setAsking('desativar')}
                    >
                        Tirar de venda
                    </Button>
                ) : (
                    <Button
                        type="button"
                        disabled={
                            !connected ||
                            busy ||
                            listing.publishBlocker !== null
                        }
                        onClick={() => setAsking('publicar')}
                    >
                        {renewal ? 'Renovar' : 'Pôr à venda'}
                    </Button>
                )}
                <Button
                    type="button"
                    variant="ghost"
                    disabled={!connected || busy}
                    onClick={() => setAsking('apagar')}
                >
                    Apagar da Etsy
                </Button>
            </div>

            {!live && listing.publishBlocker !== null && (
                <p className="mt-3 text-sm text-pretty text-muted-foreground">
                    {listing.publishBlocker}
                </p>
            )}

            {asking !== null && (
                <ConfirmDialog
                    open
                    onOpenChange={(open) => !open && setAsking(null)}
                    title={dialogs[asking].title}
                    description={dialogs[asking].description}
                    confirmLabel={dialogs[asking].confirm}
                    destructive={asking !== 'publicar'}
                    processing={busy}
                    onConfirm={run}
                />
            )}
        </Panel>
    );
}

type FormData = {
    title: string;
    description: string;
    tags: string[];
    materials: string[];
    taxonomy_id: number | null;
    who_made: string | null;
    when_made: string | null;
    shipping_profile_id: number | null;
    return_policy_id: number | null;
    readiness_state_id: number | null;
    shop_section_id: number | null;
    variants: { variant_id: number; etsy_price: string; enabled: boolean }[];
    excluded_image_ids: number[];
    sync_enabled: boolean;
};

function ContentForm({
    product,
    listing,
    own,
    effective,
    variants,
    images,
    options,
    limits,
    taxonomyResults,
}: Props) {
    const { data, setData, put, processing, errors, isDirty } =
        useForm<FormData>({
            title: own.title,
            description: own.description,
            tags: own.tags,
            materials: own.materials,
            taxonomy_id: own.taxonomyId,
            who_made: own.whoMade,
            when_made: own.whenMade,
            shipping_profile_id: own.shippingProfileId,
            return_policy_id: own.returnPolicyId,
            readiness_state_id: own.readinessStateId,
            shop_section_id: own.shopSectionId,
            variants: variants.map((variant) => ({
                variant_id: variant.id,
                etsy_price: centsToInput(variant.etsyPriceCents),
                enabled: variant.enabled,
            })),
            excluded_image_ids: images
                .filter((image) => image.excluded)
                .map((image) => image.id),
            sync_enabled: listing.syncEnabled,
        });

    // Os erros de campos dentro de listas vêm com o caminho ("variants.0.…").
    const fieldErrors = errors as Record<string, string | undefined>;

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        put(guardar(product.id).url, { preserveScroll: true });
    };

    const setVariant = (
        index: number,
        change: Partial<FormData['variants'][number]>,
    ) =>
        setData(
            'variants',
            data.variants.map((row, position) =>
                position === index ? { ...row, ...change } : row,
            ),
        );

    const titleLength = data.title.length;

    return (
        <form onSubmit={submit} className="grid gap-6">
            <Panel
                title="Texto do anúncio"
                description="Na Etsy escreve-se para quem procura em inglês. Vazio, vai o do produto."
            >
                <div className="grid gap-4">
                    <div className="grid gap-1.5">
                        <Label htmlFor="etsy-title">Título</Label>
                        <Input
                            id="etsy-title"
                            value={data.title}
                            placeholder={effective.title}
                            onChange={(event) =>
                                setData('title', event.target.value)
                            }
                        />
                        <p
                            className={cn(
                                'text-xs text-muted-foreground tabular-nums',
                                titleLength > limits.titleMax &&
                                    'text-destructive',
                            )}
                        >
                            {titleLength} de {limits.titleMax} caracteres
                        </p>
                        <InputError message={errors.title} />
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="etsy-description">Descrição</Label>
                        <Textarea
                            id="etsy-description"
                            rows={8}
                            value={data.description}
                            placeholder={effective.description}
                            onChange={(event) =>
                                setData('description', event.target.value)
                            }
                        />
                        <p className="text-xs text-muted-foreground">
                            Texto simples: a Etsy não aceita negritos nem
                            listas.
                        </p>
                        <InputError message={errors.description} />
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid content-start gap-1.5">
                            <Label htmlFor="etsy-tags">Etiquetas</Label>
                            <TagInput
                                id="etsy-tags"
                                value={data.tags}
                                onChange={(tags) => setData('tags', tags)}
                                suggestions={effective.tags}
                                max={limits.tagsMax}
                            />
                            <p className="text-xs text-pretty text-muted-foreground">
                                Até {limits.tagsMax}, cada uma até{' '}
                                {limits.tagLengthMax} caracteres. Sem nenhuma,
                                vão as do produto.
                            </p>
                            <InputError
                                message={
                                    errors.tags ??
                                    Object.entries(fieldErrors).find(([key]) =>
                                        key.startsWith('tags.'),
                                    )?.[1]
                                }
                            />
                        </div>
                        <div className="grid content-start gap-1.5">
                            <Label htmlFor="etsy-materials">Materiais</Label>
                            <TagInput
                                id="etsy-materials"
                                value={data.materials}
                                onChange={(materials) =>
                                    setData('materials', materials)
                                }
                                suggestions={effective.materials}
                                max={13}
                            />
                            <p className="text-xs text-pretty text-muted-foreground">
                                Sem nenhum, vão os materiais das variantes.
                            </p>
                            <InputError message={errors.materials} />
                        </div>
                    </div>
                </div>
            </Panel>

            <Panel
                title="Onde fica e como segue"
                description="O que não escolheres aqui vem das predefinições (Etsy → Preços e Etsy → Envios)."
            >
                <div className="grid gap-4">
                    <div className="grid gap-1.5">
                        <Label htmlFor="etsy-taxonomy">Categoria</Label>
                        <TaxonomyPicker
                            id="etsy-taxonomy"
                            value={data.taxonomy_id}
                            onChange={(taxonomyId) =>
                                setData('taxonomy_id', taxonomyId)
                            }
                            results={taxonomyResults}
                            fallbackId={effective.taxonomyId}
                        />
                        <InputError message={errors.taxonomy_id} />
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Choice
                            id="etsy-section"
                            label="Secção da loja"
                            none="A da categoria do produto"
                            value={data.shop_section_id}
                            onChange={(value) =>
                                setData('shop_section_id', value)
                            }
                            options={options.sections.map((section) => ({
                                id: section.id,
                                label: section.title,
                            }))}
                            error={errors.shop_section_id}
                        />
                        <Choice
                            id="etsy-shipping"
                            label="Perfil de envio"
                            none="O predefinido"
                            value={data.shipping_profile_id}
                            onChange={(value) =>
                                setData('shipping_profile_id', value)
                            }
                            options={options.shippingProfiles.map(
                                (profile) => ({
                                    id: profile.id,
                                    label: profile.title,
                                }),
                            )}
                            error={errors.shipping_profile_id}
                        />
                        <Choice
                            id="etsy-processing"
                            label="Perfil de processamento"
                            none="O predefinido para este produto"
                            value={data.readiness_state_id}
                            onChange={(value) =>
                                setData('readiness_state_id', value)
                            }
                            options={options.processingProfiles.map(
                                (profile) => ({
                                    id: profile.id,
                                    label: processingLabel(profile),
                                }),
                            )}
                            error={errors.readiness_state_id}
                        />
                        <Choice
                            id="etsy-returns"
                            label="Política de devolução"
                            none="A predefinida"
                            value={data.return_policy_id}
                            onChange={(value) =>
                                setData('return_policy_id', value)
                            }
                            options={options.returnPolicies.map((policy) => ({
                                id: policy.id,
                                label: returnPolicyLabel(policy),
                            }))}
                            error={errors.return_policy_id}
                        />
                        <TextChoice
                            id="etsy-who-made"
                            label="Quem fez"
                            none={`O predefinido (${whoMadeLabel(effective.whoMade ?? '')})`}
                            value={data.who_made}
                            onChange={(value) => setData('who_made', value)}
                            options={options.whoMade.map((value) => ({
                                value,
                                label: whoMadeLabel(value),
                            }))}
                            error={errors.who_made}
                        />
                        <TextChoice
                            id="etsy-when-made"
                            label="Quando foi feito"
                            none={`O predefinido (${whenMadeLabel(effective.whenMade ?? '')})`}
                            value={data.when_made}
                            onChange={(value) => setData('when_made', value)}
                            options={options.whenMade.map((value) => ({
                                value,
                                label: whenMadeLabel(value),
                            }))}
                            error={errors.when_made}
                        />
                    </div>
                </div>
            </Panel>

            <Panel
                title="Variações e preços"
                description="Cada variante ativa do produto é uma variação do anúncio. Sem preço próprio, leva o do site (mais a margem global, se houver uma)."
            >
                {variants.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        O produto não tem variantes ativas.
                    </p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b border-border/60 text-left text-xs text-muted-foreground">
                                    <th className="py-2 pr-3 font-medium">
                                        Variante
                                    </th>
                                    <th className="px-3 py-2 text-right font-medium">
                                        Site
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Preço na Etsy
                                    </th>
                                    <th className="px-3 py-2 text-right font-medium">
                                        Quantidade
                                    </th>
                                    <th className="py-2 pl-3 font-medium">
                                        Anunciar
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {variants.map((variant, position) => (
                                    <tr
                                        key={variant.id}
                                        className="border-b border-border/40 last:border-0"
                                    >
                                        <td className="py-2 pr-3">
                                            <span className="font-medium">
                                                {variant.label}
                                            </span>
                                            <div className="text-xs text-muted-foreground">
                                                {variant.sku}
                                            </div>
                                        </td>
                                        <td className="px-3 py-2 text-right tabular-nums">
                                            {formatCents(
                                                variant.sitePriceCents,
                                            )}
                                        </td>
                                        <td className="px-3 py-2">
                                            <Input
                                                inputMode="decimal"
                                                className="w-28"
                                                aria-label={`Preço na Etsy de ${variant.sku}`}
                                                value={
                                                    data.variants[position]
                                                        ?.etsy_price ?? ''
                                                }
                                                placeholder={centsToInput(
                                                    variant.effectivePriceCents,
                                                )}
                                                onChange={(event) =>
                                                    setVariant(position, {
                                                        etsy_price:
                                                            event.target.value,
                                                    })
                                                }
                                            />
                                            <div className="mt-0.5 text-xs text-muted-foreground">
                                                {priceSourceLabel(
                                                    variant.priceSource,
                                                )}
                                            </div>
                                            <InputError
                                                message={
                                                    fieldErrors[
                                                        `variants.${position}.etsy_price`
                                                    ] ??
                                                    fieldErrors[
                                                        `variants.${position}.variant_id`
                                                    ]
                                                }
                                            />
                                        </td>
                                        <td className="px-3 py-2 text-right tabular-nums">
                                            {variant.quantity}
                                        </td>
                                        <td className="py-2 pl-3">
                                            <Checkbox
                                                aria-label={`Anunciar ${variant.sku} na Etsy`}
                                                checked={
                                                    data.variants[position]
                                                        ?.enabled ?? true
                                                }
                                                onCheckedChange={(checked) =>
                                                    setVariant(position, {
                                                        enabled:
                                                            checked === true,
                                                    })
                                                }
                                            />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Panel>

            <Panel
                title="Fotografias"
                description="Vão as da galeria do produto, pela ordem de lá. Tira o visto às que não queres na Etsy — continuam no 12studio."
            >
                {images.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        O produto ainda não tem fotografias. A Etsy não publica
                        um anúncio sem pelo menos uma.
                    </p>
                ) : (
                    <ul className="grid grid-cols-[repeat(auto-fill,minmax(7.5rem,1fr))] gap-3">
                        {images.map((image) => {
                            const excluded = data.excluded_image_ids.includes(
                                image.id,
                            );

                            return (
                                <li key={image.id}>
                                    <label className="flex cursor-pointer flex-col gap-1.5 text-xs">
                                        <img
                                            src={image.url}
                                            alt={image.alt ?? ''}
                                            className={cn(
                                                'aspect-square w-full rounded-lg border border-border/60 object-cover',
                                                excluded && 'opacity-40',
                                            )}
                                        />
                                        <span className="flex items-center gap-1.5">
                                            <Checkbox
                                                checked={!excluded}
                                                onCheckedChange={(checked) =>
                                                    setData(
                                                        'excluded_image_ids',
                                                        checked === true
                                                            ? data.excluded_image_ids.filter(
                                                                  (id) =>
                                                                      id !==
                                                                      image.id,
                                                              )
                                                            : [
                                                                  ...data.excluded_image_ids,
                                                                  image.id,
                                                              ],
                                                    )
                                                }
                                            />
                                            {excluded
                                                ? 'Não vai'
                                                : image.onEtsy
                                                  ? 'Na Etsy'
                                                  : 'Vai no envio'}
                                        </span>
                                    </label>
                                </li>
                            );
                        })}
                    </ul>
                )}
                <InputError message={errors.excluded_image_ids} />
            </Panel>

            <label className="flex items-start gap-2.5 text-sm">
                <Checkbox
                    className="mt-0.5"
                    checked={data.sync_enabled}
                    onCheckedChange={(checked) =>
                        setData('sync_enabled', checked === true)
                    }
                />
                <span>
                    Manter este anúncio em dia sozinho
                    <span className="block text-xs text-pretty text-muted-foreground">
                        Depois do primeiro envio, o stock, os preços e os textos
                        seguem para a Etsy quando mudam cá; sem stock, um
                        anúncio à venda sai de venda e volta quando houver. Um
                        rascunho nunca é publicado sozinho.
                    </span>
                </span>
            </label>

            <div className="flex items-center gap-3">
                <Button type="submit" disabled={processing || !isDirty}>
                    {processing && <Spinner />}
                    Guardar
                </Button>
                <span
                    className={cn(
                        'text-sm',
                        isDirty ? 'text-warning' : 'text-muted-foreground',
                    )}
                >
                    {isDirty
                        ? 'Alterações por guardar — o envio manda o que estiver guardado.'
                        : 'Guardar fica no 12studio; para a Etsy só vai com o envio.'}
                </span>
            </div>
        </form>
    );
}

/** Uma escolha entre coisas da Etsy (por id), ou nenhuma: vale o predefinido. */
function Choice({
    id,
    label,
    none,
    value,
    onChange,
    options,
    error,
}: {
    id: string;
    label: string;
    none: string;
    value: number | null;
    onChange: (value: number | null) => void;
    options: { id: number; label: string }[];
    error?: string;
}) {
    // Um id que já não existe na Etsy mostra-se como "o predefinido".
    const known = value !== null && options.some((o) => o.id === value);

    return (
        <div className="grid content-start gap-1.5">
            <Label htmlFor={id}>{label}</Label>
            <Select
                value={known ? String(value) : NONE}
                onValueChange={(chosen) =>
                    onChange(chosen === NONE ? null : Number(chosen))
                }
            >
                <SelectTrigger id={id} className="w-full">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value={NONE}>{none}</SelectItem>
                    {options.map((option) => (
                        <SelectItem key={option.id} value={String(option.id)}>
                            {option.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
            <InputError message={error} />
        </div>
    );
}

/** O mesmo, para as escolhas que a Etsy dá por nome (`i_did`, `2020_2026`). */
function TextChoice({
    id,
    label,
    none,
    value,
    onChange,
    options,
    error,
}: {
    id: string;
    label: string;
    none: string;
    value: string | null;
    onChange: (value: string | null) => void;
    options: { value: string; label: string }[];
    error?: string;
}) {
    return (
        <div className="grid content-start gap-1.5">
            <Label htmlFor={id}>{label}</Label>
            <Select
                value={value ?? NONE}
                onValueChange={(chosen) =>
                    onChange(chosen === NONE ? null : chosen)
                }
            >
                <SelectTrigger id={id} className="w-full">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value={NONE}>{none}</SelectItem>
                    {options.map((option) => (
                        <SelectItem key={option.value} value={option.value}>
                            {option.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
            <InputError message={error} />
        </div>
    );
}

function processingLabel(profile: ProcessingOption): string {
    const state =
        PROCESSING_STATES.find((option) => option.value === profile.state)
            ?.label ?? profile.state;

    if (profile.min === null || profile.max === null) {
        return state;
    }

    return profile.unit === 'weeks'
        ? `${state} · ${profile.min}–${profile.max} semanas`
        : `${state} · ${deliveryLabel(profile.min, profile.max)}`;
}

function returnPolicyLabel(policy: ReturnPolicyOption): string {
    if (!policy.acceptsReturns && !policy.acceptsExchanges) {
        return 'Sem devoluções nem trocas';
    }

    const what = [
        policy.acceptsReturns ? 'devoluções' : null,
        policy.acceptsExchanges ? 'trocas' : null,
    ]
        .filter(Boolean)
        .join(' e ');

    return policy.deadline === null
        ? `Aceita ${what}`
        : `Aceita ${what} · ${policy.deadline} dias`;
}

/**
 * O vídeo do anúncio: um por produto. Fica guardado cá e segue no envio
 * seguinte; tirá-lo tira-o também da Etsy, se já lá estiver.
 */
function Video({
    productId,
    listing,
}: {
    productId: number;
    listing: Listing;
}) {
    const input = useRef<HTMLInputElement>(null);
    const [removing, setRemoving] = useState(false);
    const [busy, setBusy] = useState(false);
    const { setData, post, processing, errors, progress } = useForm<{
        video: File | null;
    }>({ video: null });

    return (
        <Panel
            title="Vídeo"
            description="Opcional. MP4 ou MOV; a Etsy mostra um por anúncio."
        >
            {listing.videoUrl !== null && (
                <div className="mb-4 flex flex-wrap items-start gap-4">
                    <video
                        src={listing.videoUrl}
                        controls
                        preload="metadata"
                        className="max-h-56 rounded-lg border border-border/60"
                    />
                    <div className="flex flex-col items-start gap-2 text-sm">
                        <span className="text-muted-foreground">
                            {listing.videoOnEtsy
                                ? 'Já está na Etsy.'
                                : 'Segue no próximo envio.'}
                        </span>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => setRemoving(true)}
                        >
                            Tirar o vídeo
                        </Button>
                    </div>
                </div>
            )}

            <div className="flex flex-wrap items-center gap-3">
                <input
                    ref={input}
                    type="file"
                    accept="video/mp4,video/quicktime"
                    aria-label="Ficheiro de vídeo"
                    className="text-sm file:mr-3 file:rounded-md file:border file:border-border file:bg-secondary file:px-3 file:py-1.5 file:text-sm"
                    onChange={(event) =>
                        setData('video', event.target.files?.[0] ?? null)
                    }
                />
                <Button
                    type="button"
                    variant="outline"
                    disabled={processing}
                    onClick={() =>
                        post(uploadVideo(productId).url, {
                            forceFormData: true,
                            preserveScroll: true,
                            onSuccess: () => {
                                if (input.current) {
                                    input.current.value = '';
                                }
                            },
                        })
                    }
                >
                    {processing && <Spinner />}
                    {listing.videoUrl === null
                        ? 'Carregar vídeo'
                        : 'Trocar o vídeo'}
                </Button>
                {progress && (
                    <span className="text-sm text-muted-foreground tabular-nums">
                        {progress.percentage}%
                    </span>
                )}
            </div>
            <InputError message={errors.video} />

            <ConfirmDialog
                open={removing}
                onOpenChange={setRemoving}
                title="Tirar o vídeo"
                description={
                    listing.videoOnEtsy
                        ? 'O vídeo sai do anúncio na Etsy agora, e o ficheiro é apagado do 12studio.'
                        : 'O ficheiro é apagado do 12studio. Ainda não tinha ido para a Etsy.'
                }
                confirmLabel="Tirar"
                destructive
                processing={busy}
                onConfirm={() => {
                    setBusy(true);
                    router.delete(removeVideo(productId).url, {
                        preserveScroll: true,
                        onFinish: () => {
                            setBusy(false);
                            setRemoving(false);
                        },
                    });
                }}
            />
        </Panel>
    );
}

ProductEtsyListing.layout = {
    breadcrumbs: [
        { title: 'Backoffice', href: '/admin' },
        { title: 'Produtos', href: index() },
        { title: 'Anúncio na Etsy', href: '#' },
    ],
};
