import { MenuLink } from '@/components/store/menu-link';
import { ResponsiveImage } from '@/components/store/responsive-image';
import { cn } from '@/lib/utils';
import type { BlockLink, ResponsiveImageData, StoreBlock } from '@/types/store';
import { deviceClasses, deviceVars } from './props';
import type { DeviceClasses } from './props';

type Data = {
    image: ResponsiveImageData | null;
    caption: string;
    link: BlockLink | null;
};

const RATIOS: Record<string, DeviceClasses> = {
    auto: ['aspect-auto', 'md:aspect-auto', 'lg:aspect-auto'],
    '1:1': ['aspect-square', 'md:aspect-square', 'lg:aspect-square'],
    '4:3': ['aspect-[4/3]', 'md:aspect-[4/3]', 'lg:aspect-[4/3]'],
    '16:9': ['aspect-video', 'md:aspect-video', 'lg:aspect-video'],
    '3:4': ['aspect-[3/4]', 'md:aspect-[3/4]', 'lg:aspect-[3/4]'],
};

/** Com a proporção original a altura é a da imagem; senão, enche a caixa. */
const FILL: DeviceClasses = ['h-full', 'md:h-full', 'lg:h-full'];
const HEIGHTS: Record<string, DeviceClasses> = {
    auto: ['h-auto', 'md:h-auto', 'lg:h-auto'],
    '1:1': FILL,
    '4:3': FILL,
    '16:9': FILL,
    '3:4': FILL,
};

const FITS: Record<string, DeviceClasses> = {
    cover: ['object-cover', 'md:object-cover', 'lg:object-cover'],
    contain: ['object-contain', 'md:object-contain', 'lg:object-contain'],
};

export function ImageBlock({ block }: { block: StoreBlock }) {
    const data = block.data as Data;

    if (!data.image) {
        return null;
    }

    const picture = (
        <div
            className={cn(
                'overflow-hidden rounded-[var(--image-radius-m)] bg-muted md:rounded-[var(--image-radius-t)] lg:rounded-[var(--image-radius-d)]',
                deviceClasses(block, 'ratio', RATIOS, 'auto'),
            )}
            style={deviceVars(block, 'radius', 'image-radius', 0, 'px')}
        >
            <ResponsiveImage
                image={data.image}
                sizes="(min-width: 1024px) 50vw, 100vw"
                className={cn(
                    'w-full',
                    deviceClasses(block, 'ratio', HEIGHTS, 'auto'),
                    deviceClasses(block, 'fit', FITS, 'cover'),
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
