import type { CSSProperties } from 'react';
import { MenuLink } from '@/components/store/menu-link';
import { ResponsiveImage } from '@/components/store/responsive-image';
import { cn } from '@/lib/utils';
import type { BlockLink, ResponsiveImageData, StoreBlock } from '@/types/store';
import { styleProp } from './props';

type Data = {
    image: ResponsiveImageData | null;
    caption: string;
    link: BlockLink | null;
};

const RATIOS: Record<string, string> = {
    '1:1': 'aspect-square',
    '4:3': 'aspect-[4/3]',
    '16:9': 'aspect-video',
    '3:4': 'aspect-[3/4]',
};

export function ImageBlock({ block }: { block: StoreBlock }) {
    const data = block.data as Data;

    if (!data.image) {
        return null;
    }

    const ratio = RATIOS[styleProp<string>(block, 'ratio') ?? 'auto'];
    const fit = styleProp<string>(block, 'fit') === 'contain';
    const radius = styleProp<number>(block, 'radius') ?? 0;

    const picture = (
        <div
            className={cn(
                'overflow-hidden rounded-[var(--image-radius)] bg-muted',
                ratio,
            )}
            style={{ '--image-radius': `${radius}px` } as CSSProperties}
        >
            <ResponsiveImage
                image={data.image}
                sizes="(min-width: 1024px) 50vw, 100vw"
                className={cn(
                    'w-full',
                    ratio
                        ? cn(
                              'size-full',
                              fit ? 'object-contain' : 'object-cover',
                          )
                        : 'h-auto',
                )}
            />
        </div>
    );

    return (
        <figure className="flex flex-col gap-2">
            {data.link ? (
                <MenuLink item={data.link} aria-label={data.link.label}>
                    {picture}
                </MenuLink>
            ) : (
                picture
            )}
            {data.caption && (
                <figcaption className="text-sm text-muted-foreground">
                    {data.caption}
                </figcaption>
            )}
        </figure>
    );
}
