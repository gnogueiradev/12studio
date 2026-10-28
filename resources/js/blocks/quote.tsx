import { cn } from '@/lib/utils';
import type { StoreBlock } from '@/types/store';
import { styleProp } from './props';

type Data = { text: string; author: string };

export function QuoteBlock({ block }: { block: StoreBlock }) {
    const data = block.data as Data;

    if (!data.text) {
        return null;
    }

    const large = styleProp<string>(block, 'size') === 'lg';

    return (
        <blockquote className="border-l-2 border-gold pl-6">
            <p
                className={cn(
                    'whitespace-pre-line text-foreground',
                    large ? 'text-2xl md:text-3xl' : 'text-lg md:text-xl',
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
