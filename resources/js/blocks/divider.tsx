import type { CSSProperties } from 'react';
import { cn } from '@/lib/utils';
import type { StoreBlock } from '@/types/store';
import { styleProp } from './props';

const COLORS: Record<string, string> = {
    border: 'bg-border',
    muted: 'bg-muted-foreground',
    accent: 'bg-gold',
};

export function DividerBlock({ block }: { block: StoreBlock }) {
    const thickness = styleProp<number>(block, 'thickness') ?? 1;
    const width = styleProp<number>(block, 'width') ?? 100;
    const color = styleProp<string>(block, 'color') ?? 'border';

    return (
        <hr
            className={cn(
                'mx-auto h-[var(--divider-thickness)] w-[var(--divider-width)] border-0',
                COLORS[color] ?? COLORS.border,
            )}
            style={
                {
                    '--divider-thickness': `${thickness}px`,
                    '--divider-width': `${width}%`,
                } as CSSProperties
            }
        />
    );
}
