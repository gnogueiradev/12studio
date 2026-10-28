import { cn } from '@/lib/utils';
import type { StoreBlock } from '@/types/store';
import { styleProp, TEXT_COLORS } from './props';
import { RichText } from './text';

type Data = { body: string };

const SIZES: Record<string, string> = {
    sm: 'text-sm',
    md: 'text-base',
    lg: 'text-lg',
};

export function ParagraphBlock({ block }: { block: StoreBlock }) {
    const data = block.data as Data;

    if (!data.body) {
        return null;
    }

    const size = styleProp<string>(block, 'size') ?? 'md';
    const color = styleProp<string>(block, 'color') ?? 'default';

    return (
        <RichText
            html={data.body}
            className={cn(
                SIZES[size] ?? SIZES.md,
                TEXT_COLORS[color] ?? TEXT_COLORS.default,
            )}
        />
    );
}
