import { cn } from '@/lib/utils';
import type { StoreBlock } from '@/types/store';
import { BlockSection } from './section';

type Data = { title: string; body: string };

/**
 * O HTML vem limpo do servidor: o BlockSanitizer passa-o pelo HTMLPurifier
 * (perfil `page`) antes de o gravar.
 */
export function RichText({
    html,
    className,
}: {
    html: string;
    className?: string;
}) {
    return (
        <div
            className={cn('rich-text store-rich-text', className)}
            dangerouslySetInnerHTML={{ __html: html }}
        />
    );
}

export function TextBlock({ block }: { block: StoreBlock }) {
    const data = block.data as Data;

    return (
        <BlockSection style={block.style}>
            <div
                className={cn(
                    block.variant === 'wide' ? 'max-w-4xl' : 'max-w-2xl',
                    block.style.align === 'center' && 'mx-auto',
                )}
            >
                {data.title && (
                    <h2 className="mb-5 text-2xl font-semibold tracking-tight text-balance md:text-3xl">
                        {data.title}
                    </h2>
                )}
                <RichText html={data.body} />
            </div>
        </BlockSection>
    );
}
