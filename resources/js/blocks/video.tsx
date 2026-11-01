import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { StoreBlock } from '@/types/store';
import { deviceClasses } from './props';
import type { DeviceClasses } from './props';

type Data = { url: string };

const RATIOS: Record<string, DeviceClasses> = {
    '16:9': [
        'aspect-video max-w-none',
        'md:aspect-video md:max-w-none',
        'lg:aspect-video lg:max-w-none',
    ],
    '4:3': [
        'aspect-[4/3] max-w-none',
        'md:aspect-[4/3] md:max-w-none',
        'lg:aspect-[4/3] lg:max-w-none',
    ],
    '1:1': [
        'aspect-square max-w-none',
        'md:aspect-square md:max-w-none',
        'lg:aspect-square lg:max-w-none',
    ],
    '9:16': [
        'aspect-[9/16] max-w-sm',
        'md:aspect-[9/16] md:max-w-sm',
        'lg:aspect-[9/16] lg:max-w-sm',
    ],
};

/**
 * O servidor só guarda endereços de embed do YouTube (sem cookies) e do
 * Vimeo (App\PageBuilder\VideoEmbed); o CSP só deixa emoldurar esses.
 */
export function VideoBlock({ block }: { block: StoreBlock }) {
    const data = block.data as Data;
    const t = useT();

    if (!data.url) {
        return null;
    }

    return (
        <div
            className={cn(
                'mx-auto overflow-hidden rounded-lg bg-muted',
                deviceClasses(block, 'ratio', RATIOS, '16:9'),
            )}
        >
            <iframe
                src={data.url}
                title={t('video.title')}
                loading="lazy"
                allow="fullscreen; picture-in-picture"
                referrerPolicy="strict-origin-when-cross-origin"
                className="size-full border-0"
            />
        </div>
    );
}
