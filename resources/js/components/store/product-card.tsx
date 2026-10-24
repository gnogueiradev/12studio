import { Link } from '@inertiajs/react';
import { ResponsiveImage } from '@/components/store/responsive-image';
import { useT } from '@/lib/i18n';
import { usePrice } from '@/lib/money';
import { cn } from '@/lib/utils';
import type { ProductCardData } from '@/types/store';

/** "12,90 €", "desde 12,90 €", com o preço riscado em promoção. */
export function ProductPrice({
    price,
    className,
}: {
    price: ProductCardData['price'];
    className?: string;
}) {
    const t = useT();
    const format = usePrice();

    if (price.from === null) {
        return null;
    }

    const amount = format(price.from);

    return (
        <p className={cn('flex flex-wrap items-baseline gap-x-2', className)}>
            <span className={cn(price.compareAt !== null && 'text-gold')}>
                {price.varies ? t('product.from', { price: amount }) : amount}
            </span>
            {price.compareAt !== null && (
                <s className="text-sm text-muted-foreground">
                    {format(price.compareAt)}
                </s>
            )}
        </p>
    );
}

/**
 * O cartão de produto da montra. A imagem troca para a segunda foto ao passar
 * o rato (só em ecrãs com rato). As bolinhas mostram as cores disponíveis.
 */
export function ProductCard({
    product,
    sizes = '(min-width: 1024px) 25vw, (min-width: 768px) 33vw, 50vw',
    priority = false,
}: {
    product: ProductCardData;
    sizes?: string;
    priority?: boolean;
}) {
    const t = useT();

    const badge = product.onSale
        ? t('product.badge.sale')
        : product.availability === 'sold_out'
          ? t('product.badge.sold_out')
          : product.isNew
            ? t('product.badge.new')
            : product.availability === 'made_to_order'
              ? t('product.badge.made_to_order')
              : product.availability === 'custom'
                ? t('product.badge.custom')
                : null;

    return (
        <article className="group relative flex flex-col">
            <div className="relative aspect-square overflow-hidden rounded-lg bg-muted">
                {product.image ? (
                    <>
                        <ResponsiveImage
                            image={product.image}
                            sizes={sizes}
                            priority={priority}
                            className="size-full object-cover transition-transform duration-500 group-hover:scale-[1.03]"
                        />
                        {product.hoverImage && (
                            <ResponsiveImage
                                image={{ ...product.hoverImage, alt: '' }}
                                sizes={sizes}
                                aria-hidden="true"
                                className="absolute inset-0 size-full object-cover opacity-0 transition-opacity duration-300 [@media(hover:hover)]:group-hover:opacity-100"
                            />
                        )}
                    </>
                ) : (
                    <div className="flex size-full items-center justify-center text-xs text-muted-foreground">
                        {t('product.no_image')}
                    </div>
                )}
                {badge && (
                    <span
                        className={cn(
                            'absolute top-3 left-3 rounded-full px-2.5 py-1 text-xs font-medium',
                            product.onSale
                                ? 'bg-gold text-gold-foreground'
                                : 'bg-background/90 text-foreground',
                        )}
                    >
                        {badge}
                    </span>
                )}
            </div>

            <div className="mt-3 flex flex-col gap-1 text-left">
                {product.category && (
                    <p className="text-xs text-muted-foreground">
                        {product.category}
                    </p>
                )}
                <h3 className="text-sm leading-snug font-medium">
                    <Link
                        href={product.href}
                        className="outline-none after:absolute after:inset-0 after:rounded-lg after:content-[''] focus-visible:after:ring-2 focus-visible:after:ring-ring focus-visible:after:ring-offset-2 focus-visible:after:ring-offset-background"
                    >
                        {product.name}
                    </Link>
                </h3>
                <ProductPrice price={product.price} className="text-sm" />
                {product.colors.length > 1 && (
                    <ul
                        className="mt-1 flex items-center gap-1.5"
                        aria-label={t('product.colors')}
                    >
                        {product.colors.map((color) => (
                            <li
                                key={color.name}
                                title={color.name}
                                className="size-3.5 rounded-full border border-border"
                                style={{ backgroundColor: color.hex }}
                            >
                                <span className="sr-only">{color.name}</span>
                            </li>
                        ))}
                        {product.moreColors > 0 && (
                            <li className="text-xs text-muted-foreground">
                                +{product.moreColors}
                            </li>
                        )}
                    </ul>
                )}
            </div>
        </article>
    );
}
