import { cn } from '@/lib/utils';
import type { StoreBlock } from '@/types/store';

const SIZES: Record<string, string> = { sm: 'h-8', md: 'h-16', lg: 'h-28' };

export function SpacerBlock({
    block,
    nested,
}: {
    block: StoreBlock;
    nested: boolean;
}) {
    const data = block.data as { size: string; line: boolean };

    return (
        <div
            aria-hidden="true"
            className={cn(
                'flex w-full items-center',
                // Sem casca, a largura é a da coluna.
                !nested && 'mx-auto max-w-6xl px-4',
                SIZES[data.size] ?? SIZES.md,
            )}
        >
            {data.line && <hr className="w-full border-border" />}
        </div>
    );
}
