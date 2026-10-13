import { Head, Link, router } from '@inertiajs/react';
import { Box, ExternalLink, ShieldAlert } from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/admin/confirm-dialog';
import { EtsyNav } from '@/components/admin/etsy/etsy-nav';
import { PageHeader } from '@/components/admin/page-header';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatCentsIn } from '@/lib/money';
import { cn } from '@/lib/utils';
import { desligar, index, ligar } from '@/routes/admin/etsy/anuncios';
import { edit as editProduct } from '@/routes/admin/produtos';
import { show as productListing } from '@/routes/admin/produtos/etsy';

type ProductRef = {
    id: number;
    name: string;
    /** Só vem nos ligados: o produto foi apagado e a página dele já não abre. */
    deleted?: boolean;
};

type ListingRow = {
    listingId: number;
    title: string;
    state: string;
    url: string | null;
    priceCents: number | null;
    currency: string | null;
    quantity: number | null;
    skus: string[];
    imageUrl: string | null;
    /** O produto do 12studio a que este anúncio já está ligado. */
    linkedProduct: ProductRef | null;
    /** O palpite, por referência ou por nome. Nunca se liga sozinho. */
    suggestedProduct: ProductRef | null;
};

type Props = {
    connected: boolean;
    /** O que a Etsy respondeu quando não deixou ler a loja. */
    error: string | null;
    listings: ListingRow[];
    total: number;
    perPage: number;
    filters: { state: string; page: number };
    /** Produtos ainda sem anúncio: os que se podem ligar. */
    products: ProductRef[];
};

const STATES = [
    { value: 'active', label: 'Ativos' },
    { value: 'draft', label: 'Rascunhos' },
    { value: 'inactive', label: 'Inativos' },
    { value: 'sold_out', label: 'Esgotados' },
    { value: 'expired', label: 'Expirados' },
];

function visit(state: string, page = 1) {
    router.get(
        index().url,
        { state, ...(page > 1 ? { page } : {}) },
        { preserveScroll: true, replace: true },
    );
}

/**
 * Os anúncios que a loja tem na Etsy, e a que produto do 12studio pertence
 * cada um.
 *
 * Ligar não muda nada na Etsy: só diz ao 12studio "este anúncio é este
 * produto" e traz para cá o título, a descrição e as etiquetas que lá estão,
 * para o primeiro envio não os apagar.
 */
export default function EtsyListings({
    connected,
    error,
    listings,
    total,
    perPage,
    filters,
    products,
}: Props) {
    const lastPage = Math.max(1, Math.ceil(total / perPage));

    return (
        <>
            <Head title="Anúncios na Etsy" />
            <div className="flex h-full w-full max-w-[1400px] flex-1 flex-col p-6 pb-10">
                <PageHeader
                    title="Anúncios na Etsy"
                    description="O que a loja tem na Etsy, e a que produto do 12studio pertence cada anúncio."
                />
                <EtsyNav current="anuncios" />

                {!connected && (
                    <Alert className="mt-5 max-w-3xl">
                        <ShieldAlert />
                        <AlertTitle>Ainda não há loja ligada</AlertTitle>
                        <AlertDescription>
                            Liga a loja em Definições → Etsy para ver aqui os
                            anúncios.
                        </AlertDescription>
                    </Alert>
                )}

                {error !== null && (
                    <Alert variant="destructive" className="mt-5 max-w-3xl">
                        <ShieldAlert />
                        <AlertTitle>
                            A Etsy não deixou ler os anúncios
                        </AlertTitle>
                        <AlertDescription>{error}</AlertDescription>
                    </Alert>
                )}

                {connected && (
                    <>
                        <div className="mt-5 flex flex-wrap gap-4.5 border-b border-border pb-3">
                            {STATES.map((state) => {
                                const active = filters.state === state.value;

                                return (
                                    <button
                                        key={state.value}
                                        type="button"
                                        aria-pressed={active}
                                        onClick={() => visit(state.value)}
                                        className={cn(
                                            'border-b-2 pt-0.5 pb-1.5 text-[13.5px] transition-colors',
                                            active
                                                ? 'border-gold font-semibold text-foreground'
                                                : 'border-transparent text-muted-foreground hover:text-foreground',
                                        )}
                                    >
                                        {state.label}
                                        {active && (
                                            <span className="font-normal text-muted-foreground tabular-nums">
                                                {' '}
                                                {total}
                                            </span>
                                        )}
                                    </button>
                                );
                            })}
                        </div>

                        {listings.length === 0 && error === null ? (
                            <p className="py-9 text-center text-[13px] text-muted-foreground">
                                Nenhum anúncio neste estado.
                            </p>
                        ) : (
                            <ul>
                                {listings.map((listing) => (
                                    <ListingItem
                                        key={listing.listingId}
                                        listing={listing}
                                        products={products}
                                    />
                                ))}
                            </ul>
                        )}

                        {lastPage > 1 && (
                            <nav
                                aria-label="Páginas"
                                className="mt-4.5 flex items-center gap-3 text-[12.5px] text-muted-foreground"
                            >
                                <button
                                    type="button"
                                    disabled={filters.page <= 1}
                                    onClick={() =>
                                        visit(filters.state, filters.page - 1)
                                    }
                                    className="rounded-lg border border-border px-3 py-1.5 text-foreground transition-colors hover:border-muted-foreground disabled:pointer-events-none disabled:text-muted-foreground/60"
                                >
                                    Anterior
                                </button>
                                <span className="tabular-nums">
                                    {filters.page} de {lastPage}
                                </span>
                                <button
                                    type="button"
                                    disabled={filters.page >= lastPage}
                                    onClick={() =>
                                        visit(filters.state, filters.page + 1)
                                    }
                                    className="rounded-lg border border-border px-3 py-1.5 text-foreground transition-colors hover:border-muted-foreground disabled:pointer-events-none disabled:text-muted-foreground/60"
                                >
                                    Seguinte
                                </button>
                            </nav>
                        )}
                    </>
                )}
            </div>
        </>
    );
}

function ListingItem({
    listing,
    products,
}: {
    listing: ListingRow;
    products: ProductRef[];
}) {
    // A sugestão vem escolhida, mas é o dono que carrega em "Ligar".
    const [productId, setProductId] = useState<string>(
        listing.suggestedProduct ? String(listing.suggestedProduct.id) : '',
    );
    const [busy, setBusy] = useState(false);
    const [unlinking, setUnlinking] = useState(false);

    // A sugestão pode já não estar na lista dos "por ligar" se a página
    // estiver velha; junta-se para o seletor não ficar com um valor fantasma.
    const options =
        listing.suggestedProduct &&
        !products.some((product) => product.id === listing.suggestedProduct?.id)
            ? [listing.suggestedProduct, ...products]
            : products;

    const link = () => {
        setBusy(true);
        router.post(
            ligar(Number(productId)).url,
            { listing_id: listing.listingId },
            { preserveScroll: true, onFinish: () => setBusy(false) },
        );
    };

    return (
        <li className="flex flex-wrap items-center gap-x-3.5 gap-y-2.5 border-b border-border/60 px-0.5 py-3">
            <span className="grid size-11 flex-none place-items-center overflow-hidden rounded-[9px] bg-secondary text-muted-foreground/60">
                {listing.imageUrl === null ? (
                    <Box className="size-4.5" strokeWidth={1.2} />
                ) : (
                    <img
                        src={listing.imageUrl}
                        alt=""
                        className="size-full object-cover"
                        referrerPolicy="no-referrer"
                    />
                )}
            </span>

            <span className="min-w-0 flex-[1_1_220px]">
                <span className="flex flex-wrap items-center gap-2">
                    <span className="text-sm font-medium text-pretty">
                        {listing.title}
                    </span>
                    {listing.url !== null && (
                        <a
                            href={listing.url}
                            target="_blank"
                            rel="noreferrer noopener"
                            aria-label="Abrir na Etsy"
                            className="text-muted-foreground hover:text-foreground"
                        >
                            <ExternalLink className="size-3.5" />
                        </a>
                    )}
                </span>
                <span className="mt-0.5 block text-[11.5px] text-muted-foreground">
                    {[
                        listing.skus.length > 0
                            ? listing.skus.join(', ')
                            : 'sem referências',
                        listing.quantity === null
                            ? null
                            : `${listing.quantity} un.`,
                        listing.priceCents === null
                            ? null
                            : formatCentsIn(
                                  listing.priceCents,
                                  listing.currency ?? 'EUR',
                              ),
                    ]
                        .filter((part) => part !== null)
                        .join(' · ')}
                </span>
            </span>

            {listing.linkedProduct !== null ? (
                <span className="flex flex-none items-center gap-2 text-[13px]">
                    <span className="text-muted-foreground">Ligado a</span>
                    {listing.linkedProduct.deleted ? (
                        // Sem ligação: a página de um apagado dá 404. Fica o
                        // "Desligar", que é o que há a fazer aqui.
                        <span className="font-medium">
                            {listing.linkedProduct.name}{' '}
                            <span className="font-normal text-muted-foreground">
                                (apagado)
                            </span>
                        </span>
                    ) : (
                        <Link
                            href={editProduct(listing.linkedProduct.id)}
                            className="font-medium underline-offset-4 hover:underline"
                        >
                            {listing.linkedProduct.name}
                        </Link>
                    )}
                    {!listing.linkedProduct.deleted && (
                        <Button variant="ghost" size="sm" asChild>
                            <Link
                                href={productListing(listing.linkedProduct.id)}
                            >
                                Preparar envio
                            </Link>
                        </Button>
                    )}
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => setUnlinking(true)}
                    >
                        Desligar
                    </Button>
                </span>
            ) : (
                <span className="flex flex-none flex-wrap items-center gap-2">
                    <Select value={productId} onValueChange={setProductId}>
                        <SelectTrigger
                            size="sm"
                            className="w-56 text-[13px]"
                            aria-label={`Produto para ${listing.title}`}
                        >
                            <SelectValue placeholder="Escolher produto" />
                        </SelectTrigger>
                        <SelectContent>
                            {options.map((product) => (
                                <SelectItem
                                    key={product.id}
                                    value={String(product.id)}
                                >
                                    {product.name}
                                    {product.id ===
                                        listing.suggestedProduct?.id &&
                                        ' — sugestão'}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Button
                        size="sm"
                        disabled={productId === '' || busy}
                        onClick={link}
                    >
                        Ligar
                    </Button>
                </span>
            )}

            {listing.linkedProduct !== null && (
                <ConfirmDialog
                    open={unlinking}
                    onOpenChange={setUnlinking}
                    title={`Desligar de "${listing.linkedProduct.name}"`}
                    description="O anúncio fica na Etsy tal como está; o 12studio é que deixa de o associar a este produto, e de lhe enviar preços e stock. O texto próprio da Etsy que tinhas neste produto perde-se."
                    confirmLabel="Desligar"
                    destructive
                    onConfirm={() => {
                        if (listing.linkedProduct === null) {
                            return;
                        }

                        router.delete(desligar(listing.linkedProduct.id).url, {
                            preserveScroll: true,
                            onFinish: () => setUnlinking(false),
                        });
                    }}
                />
            )}
        </li>
    );
}

EtsyListings.layout = {
    breadcrumbs: [
        { title: 'Backoffice', href: '/admin' },
        { title: 'Etsy', href: index() },
        { title: 'Anúncios', href: index() },
    ],
};
