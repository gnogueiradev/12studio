import { ResponsiveImage } from '@/components/store/responsive-image';
import { cn } from '@/lib/utils';
import type { BlockLink, ResponsiveImageData, StoreBlock } from '@/types/store';
import { LinkButton } from './link-button';
import { BlockSection } from './section';
import { RichText } from './text';

type Data = {
    eyebrow: string;
    title: string;
    body: string;
    link: BlockLink | null;
    image: ResponsiveImageData | null;
};

export function TextImageBlock({ block }: { block: StoreBlock }) {
    const data = block.data as Data;
    const imageLeft = block.variant === 'image_left';

    return (
        <BlockSection style={block.style}>
            <div
                className={cn(
                    'grid items-center gap-10 md:gap-16',
                    data.image && 'md:grid-cols-2',
                )}
            >
                <div
                    className={cn(
                        'flex max-w-xl flex-col gap-4',
                        imageLeft && 'md:order-2',
                    )}
                >
                    {data.eyebrow && (
                        <p className="text-xs font-medium tracking-[0.2em] text-gold uppercase">
                            {data.eyebrow}
                        </p>
                    )}
                    {data.title && (
                        <h2 className="text-2xl font-semibold tracking-tight text-balance md:text-3xl">
                            {data.title}
                        </h2>
                    )}
                    {data.body && <RichText html={data.body} />}
                    {data.link && (
                        <div className="mt-2">
                            <LinkButton link={data.link} variant="outline" />
                        </div>
                    )}
                </div>
                {data.image && (
                    <div className="aspect-[4/3] overflow-hidden rounded-xl bg-muted">
                        <ResponsiveImage
                            image={data.image}
                            sizes="(min-width: 768px) 50vw, 100vw"
                            className="size-full object-cover"
                        />
                    </div>
                )}
            </div>
        </BlockSection>
    );
}
