import { cn } from '@/lib/utils';
import type { StoreBlock } from '@/types/store';
import { deviceClasses, deviceVars } from './props';
import type { DeviceClasses } from './props';

const COLORS: Record<string, DeviceClasses> = {
    border: ['bg-border', 'md:bg-border', 'lg:bg-border'],
    muted: [
        'bg-muted-foreground',
        'md:bg-muted-foreground',
        'lg:bg-muted-foreground',
    ],
    accent: ['bg-gold', 'md:bg-gold', 'lg:bg-gold'],
};

export function DividerBlock({ block }: { block: StoreBlock }) {
    return (
        <hr
            className={cn(
                'mx-auto h-[var(--divider-thickness-m)] w-[var(--divider-width-m)] border-0 md:h-[var(--divider-thickness-t)] md:w-[var(--divider-width-t)] lg:h-[var(--divider-thickness-d)] lg:w-[var(--divider-width-d)]',
                deviceClasses(block, 'color', COLORS, 'border'),
            )}
            style={{
                ...deviceVars(block, 'thickness', 'divider-thickness', 1, 'px'),
                ...deviceVars(block, 'width', 'divider-width', 100, '%'),
            }}
        />
    );
}
