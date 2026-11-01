import { cn } from '@/lib/utils';
import type { StoreBlock } from '@/types/store';
import { deviceClasses } from './props';
import type { DeviceClasses } from './props';

type Data = { text: string; author: string };

const SIZES: Record<string, DeviceClasses> = {
    md: ['text-lg', 'md:text-xl', 'lg:text-xl'],
    lg: ['text-2xl', 'md:text-3xl', 'lg:text-3xl'],
};

export function QuoteBlock({ block }: { block: StoreBlock }) {
    const data = block.data as Data;

    if (!data.text) {
        return null;
    }

    return (
        <blockquote className="border-l-2 border-gold pl-6">
            <p
                className={cn(
                    'whitespace-pre-line text-foreground',
                    deviceClasses(block, 'size', SIZES, 'md'),
                )}
            >
                {data.text}
            </p>
            {data.author && (
                <footer className="mt-3 text-sm text-muted-foreground">
                    {data.author}
                </footer>
            )}
        </blockquote>
    );
}
