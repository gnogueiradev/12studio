import { ProductCard } from '@/components/store/product-card';
import type { ProductCardData, StoreBlock } from '@/types/store';
import { BlockHeading, BlockSection } from './section';

type Data = { title: string; products: ProductCardData[] };

export function ProductRelatedBlock({
    block,
    nested,
}: {
    block: StoreBlock;
    nested: boolean;
}) {
    const data = block.data as Data;

    if (data.products.length === 0) {
        return null;
    }

    return (
        <BlockSection shell={!nested} style={block.style}>
            <BlockHeading title={data.title} align={block.style.align} />
            <div className="grid grid-cols-2 gap-x-4 gap-y-10 md:grid-cols-4 md:gap-x-6">
                {data.products.map((product) => (
                    <ProductCard
                        key={product.id}
                        product={product}
                        sizes="(min-width: 768px) 25vw, 50vw"
                    />
                ))}
            </div>
        </BlockSection>
    );
}
