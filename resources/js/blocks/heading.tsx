import { cn } from '@/lib/utils';
import type { StoreBlock } from '@/types/store';
import { deviceClasses, styleProp, TEXT_COLORS } from './props';
import type { DeviceClasses } from './props';

type Data = { text: string };

const TAGS = { h1: 'h1', h2: 'h2', h3: 'h3', h4: 'h4' } as const;

const SIZES: Record<string, DeviceClasses> = {
    sm: ['text-lg', 'md:text-lg', 'lg:text-lg'],
    md: ['text-xl', 'md:text-2xl', 'lg:text-2xl'],
    lg: ['text-2xl', 'md:text-3xl', 'lg:text-3xl'],
    xl: ['text-3xl', 'md:text-4xl', 'lg:text-4xl'],
    '2xl': ['text-4xl', 'md:text-5xl', 'lg:text-5xl'],
};

export function HeadingBlock({ block }: { block: StoreBlock }) {
    const data = block.data as Data;

    if (!data.text) {
        return null;
    }

    // A tag é uma só: o HTML não muda com o tamanho do ecrã.
    const level = styleProp<string>(block, 'level') ?? 'h2';
    const Tag = TAGS[level as keyof typeof TAGS] ?? 'h2';

    return (
        <Tag
            className={cn(
                'font-semibold tracking-tight text-balance',
                deviceClasses(block, 'size', SIZES, 'lg'),
                deviceClasses(block, 'color', TEXT_COLORS, 'default'),
            )}
        >
            {data.text}
        </Tag>
    );
}
