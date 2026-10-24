import { cn } from '@/lib/utils';
import type { StoreBlock } from '@/types/store';

const SIZES: Record<string, string> = { sm: 'h-8', md: 'h-16', lg: 'h-28' };

export function SpacerBlock({ block }: { block: StoreBlock }) {
    const data = block.data as { size: string; line: boolean };

    return (
        <div
            aria-hidden="true"
            className={cn(
                'mx-auto flex w-full max-w-6xl items-center px-4',
                SIZES[data.size] ?? SIZES.md,
            )}
        >
            {data.line && <hr className="w-full border-border" />}
        </div>
    );
}
