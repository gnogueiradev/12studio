import type { ComponentType } from 'react';
import type { StoreBlock } from '@/types/store';
import { BannerBlock } from './banner';
import { BreadcrumbBlock } from './breadcrumb';
import { ButtonBlock } from './button';
import { CatalogGridBlock } from './catalog-grid';
import { CatalogHeaderBlock } from './catalog-header';
import { CategoriesBlock } from './categories';
import { ContactBlock } from './contact';
import { CustomOrderBlock } from './custom-order';
import { DividerBlock } from './divider';
import { FaqBlock } from './faq';
import { GalleryBlock } from './gallery';
import { HeadingBlock } from './heading';
import { HeroBlock } from './hero';
import { IconBlock } from './icon';
import { IconListBlock } from './icon-list';
import { ImageBlock } from './image';
import { ParagraphBlock } from './paragraph';
import { ProductGridBlock } from './product-grid';
import { ProductMainBlock } from './product-main';
import { ProductRelatedBlock } from './product-related';
import { QuoteBlock } from './quote';
import { SpacerBlock } from './spacer';
import { StepsBlock } from './steps';
import { TestimonialsBlock } from './testimonials';
import { TextBlock } from './text';
import { TextImageBlock } from './text-image';
import { TrustBlock } from './trust';

import { VideoBlock } from './video';
export type BlockProps = { block: StoreBlock; first: boolean };

/**
 * `type` do bloco → componente. Tem de ter os mesmos tipos que o
 * App\PageBuilder\BlockRegistry (o BlockComponentsTest compara os dois).
 */
export const BLOCK_COMPONENTS: Record<string, ComponentType<BlockProps>> = {
    hero: HeroBlock,
    product_grid: ProductGridBlock,
    categories: CategoriesBlock,
    text: TextBlock,
    text_image: TextImageBlock,
    banner: BannerBlock,
    gallery: GalleryBlock,
    steps: StepsBlock,
    faq: FaqBlock,
    testimonials: TestimonialsBlock,
    trust: TrustBlock,
    contact: ContactBlock,
    custom_order: CustomOrderBlock,
    spacer: SpacerBlock,
    heading: HeadingBlock,
    paragraph: ParagraphBlock,
    image: ImageBlock,
    button: ButtonBlock,
    icon: IconBlock,
    divider: DividerBlock,
    video: VideoBlock,
    icon_list: IconListBlock,
    quote: QuoteBlock,
    catalog_header: CatalogHeaderBlock,
    catalog_grid: CatalogGridBlock,
    breadcrumb: BreadcrumbBlock,
    product_main: ProductMainBlock,
    product_related: ProductRelatedBlock,
};

/** Os blocos de uma página, por ordem. Um tipo sem componente não aparece. */
export function BlockList({ blocks }: { blocks: StoreBlock[] }) {
    return (
        <>
            {blocks.map((block, index) => {
                const Component = BLOCK_COMPONENTS[block.type];

                return Component ? (
                    <div key={block.id} data-block-id={block.id}>
                        <Component block={block} first={index === 0} />
                    </div>
                ) : null;
            })}
        </>
    );
}
