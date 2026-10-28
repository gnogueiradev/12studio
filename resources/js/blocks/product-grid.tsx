import { ProductCard } from '@/components/store/product-card';
import { cn } from '@/lib/utils';
import type { BlockLink, ProductCardData, StoreBlock } from '@/types/store';
import { LinkButton } from './link-button';
import { BlockHeading, BlockSection } from './section';

type Data = {
    title: string;
    subtitle: string;
    link: BlockLink | null;
    columns: number;
    products: ProductCardData[];
};

const COLUMNS: Record<number, string> = {
    2: 'grid-cols-2',
    3: 'grid-cols-2 md:grid-cols-3',
    4: 'grid-cols-2 md:grid-cols-3 lg:grid-cols-4',
};

const SIZES: Record<number, string> = {
    2: '50vw',
    3: '(min-width: 768px) 33vw, 50vw',
    4: '(min-width: 1024px) 25vw, (min-width: 768px) 33vw, 50vw',
};

export function ProductGridBlock({ block }: { block: StoreBlock }) {
    const data = block.data as Data;

    if (data.products.length === 0) {
        return null;
    }

    return (
        <BlockSection style={block.style}>
            <BlockHeading
                title={data.title}
                subtitle={data.subtitle}
                align={block.style.align}
            >
                {block.style.align !== 'center' && (
                    <LinkButton
                        link={data.link}
                        variant="outline"
                        size="default"
                    />
                )}
            </BlockHeading>
            <div
                className={cn(
                    'grid gap-x-4 gap-y-10 md:gap-x-6',
                    COLUMNS[data.columns] ?? COLUMNS[4],
                )}
            >
                {data.products.map((product) => (
                    <ProductCard
                        key={product.id}
                        product={product}
                        sizes={SIZES[data.columns] ?? SIZES[4]}
                    />
                ))}
            </div>
            {block.style.align === 'center' && data.link && (
                <div className="mt-10 flex justify-center">
                    <LinkButton
                        link={data.link}
                        variant="outline"
                        size="default"
                    />
                </div>
            )}
        </BlockSection>
    );
}
