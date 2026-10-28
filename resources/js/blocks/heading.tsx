import { cn } from '@/lib/utils';
import type { StoreBlock } from '@/types/store';
import { styleProp, TEXT_COLORS } from './props';

type Data = { text: string };

const TAGS = { h1: 'h1', h2: 'h2', h3: 'h3', h4: 'h4' } as const;

const SIZES: Record<string, string> = {
    sm: 'text-lg',
    md: 'text-xl md:text-2xl',
    lg: 'text-2xl md:text-3xl',
    xl: 'text-3xl md:text-4xl',
    '2xl': 'text-4xl md:text-5xl',
};

export function HeadingBlock({ block }: { block: StoreBlock }) {
    const data = block.data as Data;

    if (!data.text) {
        return null;
    }

    const level = styleProp<string>(block, 'level') ?? 'h2';
    const Tag = TAGS[level as keyof typeof TAGS] ?? 'h2';
    const size = styleProp<string>(block, 'size') ?? 'lg';
    const color = styleProp<string>(block, 'color') ?? 'default';

    return (
        <Tag
            className={cn(
                'font-semibold tracking-tight text-balance',
                SIZES[size] ?? SIZES.lg,
                TEXT_COLORS[color] ?? TEXT_COLORS.default,
            )}
        >
            {data.text}
        </Tag>
    );
}
