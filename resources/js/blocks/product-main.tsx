import { Link, usePage } from '@inertiajs/react';
import {
    ChevronLeft,
    ChevronRight,
    Clock,
    Eye,
    Mail,
    Minus,
    Plus,
    Sparkles,
    Truck,
} from 'lucide-react';
import { useMemo, useState, useSyncExternalStore } from 'react';
import type { ReactNode } from 'react';
import { ContactDetails } from '@/components/store/contact-details';
import { ProductPrice } from '@/components/store/product-card';
import { ResponsiveImage } from '@/components/store/responsive-image';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { useT } from '@/lib/i18n';
import { usePrice } from '@/lib/money';
import { cn } from '@/lib/utils';
import type {
    ProductDetailData,
    ProductImageData,
    ProductVariantData,
    StoreBlock,
} from '@/types/store';
import { RichText } from './text';

type Data = {
    thumbnails: 'bottom' | 'side';
    show_specs: boolean;
    shipping_title: string;
    shipping_text: string;
    custom_note: string;
    interest_note: string;
};

type Dimension = 'colorId' | 'materialId' | 'size';

function subscribeToHistory(callback: () => void): () => void {
    window.addEventListener('popstate', callback);

    return () => window.removeEventListener('popstate', callback);
}

function readVariantFromUrl(): number | null {
    const value = Number(
        new URLSearchParams(window.location.search).get('variante'),
    );

    return Number.isInteger(value) && value > 0 ? value : null;
}

/**
 * A variante escolhida. Escolher um valor (uma cor, um tamanho) procura a
 * variante que o tem e que mantém o mais possível o resto da escolha — nunca
 * se fica numa combinação que não existe.
 */
function useVariantSelection(product: ProductDetailData) {
    // A escolha que vem no link (?variante=). Pelo useSyncExternalStore: o
    // servidor não vê o URL do browser (dá null), e o React acerta a
    // diferença na hidratação sem avisos.
    const fromUrl = useSyncExternalStore(
        subscribeToHistory,
        readVariantFromUrl,
        () => null,
    );
    const [chosenId, setChosenId] = useState<number | null>(null);

    const selectedId =
        chosenId ??
        (product.variants.some((variant) => variant.id === fromUrl)
            ? fromUrl
            : product.defaultVariantId);

    const selected =
        product.variants.find((variant) => variant.id === selectedId) ?? null;

    const select = (variant: ProductVariantData) => {
        setChosenId(variant.id);

        // O link copiado leva a escolha. Sem visita nova: só o endereço.
        const url = new URL(window.location.href);
        url.searchParams.set('variante', String(variant.id));
        window.history.replaceState(window.history.state, '', url);
    };

    const choose = (dimension: Dimension, value: number | string) => {
        const candidates = product.variants.filter(
            (variant) => variant[dimension] === value,
        );
        const others = (['colorId', 'materialId', 'size'] as const).filter(
            (other) => other !== dimension,
        );
        const score = (variant: ProductVariantData) =>
            others.filter(
                (other) => selected && variant[other] === selected[other],
            ).length;
        const best = [...candidates].sort((a, b) => score(b) - score(a))[0];

        if (best) {
            select(best);
        }
    };

    /** Há uma variante com este valor e o resto da escolha atual? */
    const fits = (dimension: Dimension, value: number | string) =>
        product.variants.some(
            (variant) =>
                variant[dimension] === value &&
                (['colorId', 'materialId', 'size'] as const)
                    .filter((other) => other !== dimension)
                    .every(
                        (other) =>
                            !selected || variant[other] === selected[other],
                    ),
        );

    return { selected, choose, fits };
}

function Gallery({
    images,
    name,
    thumbnails,
}: {
    images: ProductImageData[];
    name: string;
    thumbnails: Data['thumbnails'];
}) {
    const t = useT();
    const [index, setIndex] = useState(0);
    const [zoom, setZoom] = useState(false);
    const current = images[Math.min(index, images.length - 1)];

    if (!current) {
        return (
            <div className="flex aspect-square items-center justify-center rounded-xl bg-muted text-sm text-muted-foreground">
                {t('product.no_image')}
            </div>
        );
    }

    const go = (step: number) =>
        setIndex((value) => (value + step + images.length) % images.length);

    return (
        <div
            className={cn(
                'flex flex-col gap-3',
                thumbnails === 'side' && 'md:flex-row-reverse',
            )}
        >
            <div className="group relative flex-1 overflow-hidden rounded-xl bg-muted">
                <button
                    type="button"
                    onClick={() => setZoom(true)}
                    className="block aspect-square w-full cursor-zoom-in"
                    aria-label={t('gallery.open', {
                        n: index + 1,
                        total: images.length,
                    })}
                >
                    <ResponsiveImage
                        image={current}
                        sizes="(min-width: 768px) 50vw, 100vw"
                        priority
                        className="size-full object-cover"
                    />
                </button>
                {images.length > 1 && (
                    <>
                        <Button
                            variant="secondary"
                            size="icon"
                            className="absolute top-1/2 left-3 -translate-y-1/2 rounded-full opacity-90 md:opacity-0 md:group-hover:opacity-90"
                            onClick={() => go(-1)}
                            aria-label={t('gallery.previous')}
                        >
                            <ChevronLeft aria-hidden="true" />
                        </Button>
                        <Button
                            variant="secondary"
                            size="icon"
                            className="absolute top-1/2 right-3 -translate-y-1/2 rounded-full opacity-90 md:opacity-0 md:group-hover:opacity-90"
                            onClick={() => go(1)}
                            aria-label={t('gallery.next')}
                        >
                            <ChevronRight aria-hidden="true" />
                        </Button>
                    </>
                )}
            </div>

            {images.length > 1 && (
                <ul
                    className={cn(
                        'flex gap-2 overflow-x-auto',
                        thumbnails === 'side' &&
                            'md:w-20 md:flex-col md:overflow-x-visible',
                    )}
                >
                    {images.map((image, position) => (
                        <li key={image.id} className="shrink-0">
                            <button
                                type="button"
                                onClick={() => setIndex(position)}
                                aria-label={t('detail.image', {
                                    n: position + 1,
                                    total: images.length,
                                })}
                                aria-current={position === index}
                                className="block size-16 overflow-hidden rounded-md border-2 border-transparent bg-muted aria-[current=true]:border-foreground md:size-20"
                            >
                                <ResponsiveImage
                                    image={{ ...image, alt: '' }}
                                    sizes="80px"
                                    className="size-full object-cover"
                                />
                            </button>
                        </li>
                    ))}
                </ul>
            )}

            <Dialog open={zoom} onOpenChange={setZoom}>
                <DialogContent
                    className="store-theme max-w-[min(96vw,1200px)] border-0 bg-background p-2 sm:max-w-[min(96vw,1200px)]"
                    onKeyDown={(event) => {
                        if (event.key === 'ArrowRight') {
                            go(1);
                        }

                        if (event.key === 'ArrowLeft') {
                            go(-1);
                        }
                    }}
                >
                    <DialogTitle className="sr-only">{name}</DialogTitle>
                    <ResponsiveImage
                        image={current}
                        sizes="96vw"
                        className="max-h-[85vh] w-full rounded-md object-contain"
                    />
                </DialogContent>
            </Dialog>
        </div>
    );
}

function Section({
    title,
    children,
    open = false,
}: {
    title: string;
    children: ReactNode;
    open?: boolean;
}) {
    return (
        <details open={open} className="group border-b border-border">
            <summary className="flex cursor-pointer list-none items-center justify-between gap-4 py-4 text-sm font-semibold [&::-webkit-details-marker]:hidden">
                {title}
                <Plus
                    aria-hidden="true"
                    className="size-4 text-muted-foreground group-open:hidden"
                />
                <Minus
                    aria-hidden="true"
                    className="hidden size-4 text-muted-foreground group-open:block"
                />
            </summary>
            <div className="pb-5 text-sm">{children}</div>
        </details>
    );
}

function OptionButton({
    active,
    fits,
    onClick,
    children,
    title,
}: {
    active: boolean;
    fits: boolean;
    onClick: () => void;
    children: ReactNode;
    title?: string;
}) {
    const t = useT();

    return (
        <button
            type="button"
            onClick={onClick}
            aria-pressed={active}
            title={fits ? title : `${title ?? ''} — ${t('detail.unavailable')}`}
            className={cn(
                'rounded-md border px-3.5 py-2 text-sm transition-colors',
                active
                    ? 'border-foreground bg-foreground text-background'
                    : 'border-border hover:border-foreground/50',
                !fits && !active && 'border-dashed text-muted-foreground',
            )}
        >
            {children}
        </button>
    );
}

/**
 * Até às conversas (fase seguinte), "Tenho interesse" abre um email já com a
 * peça e a variante escolhidas, mais os contactos da loja.
 */
function InterestSheet({
    open,
    onOpenChange,
    product,
    variantLabel,
    price,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    product: ProductDetailData;
    variantLabel: string;
    price: string | null;
}) {
    const t = useT();
    const { storefront } = usePage().props;
    const email = storefront?.contact.email;

    const mailto = email
        ? `mailto:${email}?subject=${encodeURIComponent(
              t('detail.mail_subject', { product: product.name }),
          )}&body=${encodeURIComponent(
              t('detail.mail_body', {
                  product: product.name,
                  variant: variantLabel || '—',
              }) +
                  '\n\n' +
                  (typeof window !== 'undefined' ? window.location.href : ''),
          )}`
        : null;

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent className="store-theme flex w-full flex-col gap-0 bg-background text-foreground sm:max-w-md">
                <SheetHeader className="border-b border-border/60 p-6">
                    <SheetTitle className="text-xl">
                        {t('detail.interest')}
                    </SheetTitle>
                    <SheetDescription>
                        {t('detail.interest_intro')}
                    </SheetDescription>
                </SheetHeader>
                <div className="flex flex-1 flex-col gap-6 overflow-y-auto p-6">
                    <div className="flex gap-4 rounded-lg border border-border/60 p-3">
                        {product.images[0] && (
                            <ResponsiveImage
                                image={{ ...product.images[0], alt: '' }}
                                sizes="64px"
                                className="size-16 shrink-0 rounded-md object-cover"
                            />
                        )}
                        <div className="min-w-0 text-sm">
                            <p className="text-xs text-muted-foreground">
                                {t('detail.selected')}
                            </p>
                            <p className="font-medium">{product.name}</p>
                            {variantLabel && (
                                <p className="text-muted-foreground">
                                    {variantLabel}
                                </p>
                            )}
                            {price && <p className="mt-1">{price}</p>}
                        </div>
                    </div>
                    <p className="rounded-lg bg-accent p-4 text-sm text-accent-foreground">
                        {t('detail.interest_soon')}
                    </p>
                    {mailto && (
                        <Button asChild size="lg">
                            <a href={mailto}>
                                <Mail aria-hidden="true" />
                                {t('detail.write_email')}
                            </a>
                        </Button>
                    )}
                    {storefront && (
                        <ContactDetails
                            contact={storefront.contact}
                            social={storefront.social}
                        />
                    )}
                </div>
            </SheetContent>
        </Sheet>
    );
}

export function ProductMainBlock({ block }: { block: StoreBlock }) {
    const data = block.data as Data;
    const t = useT();
    const format = usePrice();
    const { product } = usePage().props as unknown as {
        product: ProductDetailData;
    };
    const { selected, choose, fits } = useVariantSelection(product);
    const [interestOpen, setInterestOpen] = useState(false);

    // A galeria da cor escolhida (as fotos dela primeiro e as gerais a
    // seguir); sem fotos dessa cor, todas.
    const images = useMemo(() => {
        const colorId = selected?.colorId ?? null;
        const own = product.images.filter(
            (image) =>
                (colorId !== null && image.colorId === colorId) ||
                (selected !== null && image.variantId === selected.id),
        );

        if (own.length === 0) {
            return product.images;
        }

        return [
            ...own,
            ...product.images.filter(
                (image) =>
                    image.colorId === null &&
                    image.variantId === null &&
                    !own.includes(image),
            ),
        ];
    }, [product.images, selected]);

    const colorName = product.colors.find(
        (color) => color.id === selected?.colorId,
    )?.name;
    const materialName = product.materials.find(
        (material) => material.id === selected?.materialId,
    )?.name;
    const variantLabel = [colorName, materialName, selected?.size]
        .filter(Boolean)
        .join(' · ');

    const availability = selected
        ? product.availability === 'ready' && !selected.ready
            ? 'made_to_order'
            : product.availability
        : product.availability;

    const price = selected
        ? {
              from: selected.price,
              to: selected.price,
              compareAt: selected.compareAt,
              varies: false,
          }
        : product.price;

    const specs = selected?.specs ?? {};
    const hasSpecs =
        data.show_specs &&
        (materialName ||
            specs.length ||
            specs.width ||
            specs.height ||
            specs.weight);

    const galleryRight = block.variant === 'gallery_right';

    return (
        <section className="mx-auto w-full max-w-6xl px-4 py-6 md:py-10">
            {product.preview && (
                <p className="mb-6 flex items-center gap-2 rounded-lg bg-warning-soft px-4 py-3 text-sm text-warning-soft-foreground">
                    <Eye aria-hidden="true" className="size-4" />
                    {t('detail.preview')}
                </p>
            )}

            <div className="grid gap-8 md:grid-cols-2 md:gap-12 lg:gap-16">
                <div className={cn(galleryRight && 'md:order-2')}>
                    <Gallery
                        images={images}
                        name={product.name}
                        thumbnails={data.thumbnails}
                    />
                </div>

                <div className="flex flex-col gap-6 md:sticky md:top-24 md:self-start">
                    <div className="flex flex-col gap-2">
                        {product.category && (
                            <Link
                                href={product.category.href}
                                className="text-sm text-muted-foreground hover:text-foreground"
                            >
                                {product.category.name}
                            </Link>
                        )}
                        <h1 className="text-3xl font-semibold tracking-tight text-balance md:text-4xl">
                            {product.name}
                        </h1>
                        {!product.isCustom && (
                            <ProductPrice price={price} className="text-xl" />
                        )}
                    </div>

                    <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
                        <span
                            className={cn(
                                'inline-flex items-center gap-1.5 rounded-full px-3 py-1 font-medium',
                                availability === 'ready'
                                    ? 'bg-success-soft text-success-soft-foreground'
                                    : availability === 'sold_out'
                                      ? 'bg-muted text-muted-foreground'
                                      : 'bg-accent text-accent-foreground',
                            )}
                        >
                            {availability === 'ready' ? (
                                <Truck
                                    aria-hidden="true"
                                    className="size-3.5"
                                />
                            ) : availability === 'custom' ? (
                                <Sparkles
                                    aria-hidden="true"
                                    className="size-3.5"
                                />
                            ) : (
                                <Clock
                                    aria-hidden="true"
                                    className="size-3.5"
                                />
                            )}
                            {t(`detail.availability.${availability}`)}
                        </span>
                        {product.leadDays &&
                            (availability === 'ready' ||
                                availability === 'made_to_order') && (
                                <span className="text-muted-foreground">
                                    {t(`detail.lead.${availability}`, {
                                        min: product.leadDays[0],
                                        max: product.leadDays[1],
                                    })}
                                </span>
                            )}
                    </div>

                    {product.colors.length > 0 && (
                        <fieldset>
                            <legend className="mb-2.5 text-sm">
                                {t('detail.color')}
                                {colorName && (
                                    <span className="text-muted-foreground">
                                        : {colorName}
                                    </span>
                                )}
                            </legend>
                            <div className="flex flex-wrap gap-2.5">
                                {product.colors.map((color) => {
                                    const active =
                                        selected?.colorId === color.id;
                                    const ok = fits('colorId', color.id);

                                    return (
                                        <button
                                            key={color.id}
                                            type="button"
                                            onClick={() =>
                                                choose('colorId', color.id)
                                            }
                                            aria-pressed={active}
                                            title={color.name}
                                            className={cn(
                                                'size-9 rounded-full border border-border ring-offset-2 ring-offset-background transition-shadow',
                                                active &&
                                                    'ring-2 ring-foreground',
                                                !ok && !active && 'opacity-40',
                                            )}
                                            style={{
                                                backgroundColor: color.hex,
                                            }}
                                        >
                                            <span className="sr-only">
                                                {color.name}
                                            </span>
                                        </button>
                                    );
                                })}
                            </div>
                        </fieldset>
                    )}

                    {product.materials.length > 1 && (
                        <fieldset>
                            <legend className="mb-2.5 text-sm">
                                {t('detail.material')}
                            </legend>
                            <div className="flex flex-wrap gap-2">
                                {product.materials.map((material) => (
                                    <OptionButton
                                        key={material.id}
                                        active={
                                            selected?.materialId === material.id
                                        }
                                        fits={fits('materialId', material.id)}
                                        onClick={() =>
                                            choose('materialId', material.id)
                                        }
                                        title={material.name}
                                    >
                                        {material.name}
                                    </OptionButton>
                                ))}
                            </div>
                        </fieldset>
                    )}

                    {product.sizes.length > 1 && (
                        <fieldset>
                            <legend className="mb-2.5 text-sm">
                                {t('detail.size')}
                            </legend>
                            <div className="flex flex-wrap gap-2">
                                {product.sizes.map((size) => (
                                    <OptionButton
                                        key={size}
                                        active={selected?.size === size}
                                        fits={fits('size', size)}
                                        onClick={() => choose('size', size)}
                                        title={size}
                                    >
                                        {size}
                                    </OptionButton>
                                ))}
                            </div>
                        </fieldset>
                    )}

                    {product.personalization && (
                        <p className="rounded-lg bg-muted px-4 py-3 text-sm">
                            {t('detail.personalizable', {
                                fields: product.personalization.fields
                                    .map((field) => field.label)
                                    .join(', '),
                            })}
                            {product.personalization.surcharge !== null &&
                                product.personalization.surcharge > 0 && (
                                    <span className="text-muted-foreground">
                                        {' '}
                                        (
                                        {t('detail.surcharge', {
                                            price: format(
                                                product.personalization
                                                    .surcharge,
                                            ),
                                        })}
                                        )
                                    </span>
                                )}
                        </p>
                    )}

                    <div className="flex flex-col gap-2.5">
                        <Button
                            size="lg"
                            className="h-12 text-base"
                            onClick={() => setInterestOpen(true)}
                        >
                            {t('detail.interest')}
                        </Button>
                        {data.interest_note && (
                            <p className="text-center text-xs text-muted-foreground">
                                {data.interest_note}
                            </p>
                        )}
                    </div>

                    {product.isCustom && data.custom_note && (
                        <p className="text-sm text-muted-foreground">
                            {data.custom_note}
                        </p>
                    )}

                    <div className="border-t border-border">
                        {product.description && (
                            <Section title={t('detail.description')} open>
                                <RichText html={product.description} />
                            </Section>
                        )}
                        {hasSpecs && (
                            <Section title={t('detail.specs')}>
                                <dl className="grid grid-cols-[auto_1fr] gap-x-6 gap-y-2">
                                    {materialName && (
                                        <>
                                            <dt className="text-muted-foreground">
                                                {t('detail.material')}
                                            </dt>
                                            <dd>{materialName}</dd>
                                        </>
                                    )}
                                    {(specs.length ||
                                        specs.width ||
                                        specs.height) && (
                                        <>
                                            <dt className="text-muted-foreground">
                                                {t('detail.dimensions')}
                                            </dt>
                                            <dd>
                                                {[
                                                    specs.length,
                                                    specs.width,
                                                    specs.height,
                                                ]
                                                    .filter(Boolean)
                                                    .map((mm) =>
                                                        (
                                                            (mm as number) / 10
                                                        ).toLocaleString(),
                                                    )
                                                    .join(' × ')}{' '}
                                                cm
                                            </dd>
                                        </>
                                    )}
                                    {specs.weight && (
                                        <>
                                            <dt className="text-muted-foreground">
                                                {t('detail.weight')}
                                            </dt>
                                            <dd>{specs.weight} g</dd>
                                        </>
                                    )}
                                </dl>
                            </Section>
                        )}
                        {data.shipping_text && (
                            <Section title={data.shipping_title}>
                                <RichText html={data.shipping_text} />
                            </Section>
                        )}
                    </div>
                </div>
            </div>

            {/* Telemóvel: o preço e o botão ficam sempre à mão. */}
            <div className="fixed inset-x-0 bottom-0 z-30 flex items-center gap-3 border-t border-border/60 bg-background/95 px-4 py-3 backdrop-blur md:hidden">
                <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium">
                        {product.name}
                    </p>
                    {!product.isCustom && (
                        <ProductPrice
                            price={price}
                            className="text-sm text-muted-foreground"
                        />
                    )}
                </div>
                <Button onClick={() => setInterestOpen(true)}>
                    {t('detail.interest')}
                </Button>
            </div>

            <InterestSheet
                open={interestOpen}
                onOpenChange={setInterestOpen}
                product={product}
                variantLabel={variantLabel}
                price={selected ? format(selected.price) : null}
            />
        </section>
    );
}
