import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { StoreBlock } from '@/types/store';
import { styleProp } from './props';

type Data = { url: string };

const RATIOS: Record<string, string> = {
    '16:9': 'aspect-video',
    '4:3': 'aspect-[4/3]',
    '1:1': 'aspect-square',
    '9:16': 'mx-auto aspect-[9/16] max-w-sm',
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

    const ratio = styleProp<string>(block, 'ratio') ?? '16:9';

    return (
        <div
            className={cn(
                'overflow-hidden rounded-lg bg-muted',
                RATIOS[ratio] ?? RATIOS['16:9'],
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
