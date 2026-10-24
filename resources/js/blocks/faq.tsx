import { Plus } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { StoreBlock } from '@/types/store';
import { BlockSection } from './section';
import { RichText } from './text';

type Data = { title: string; items: { question: string; answer: string }[] };

/**
 * Acordeão com <details>: abre e fecha sem JavaScript, funciona com teclado e
 * leitores de ecrã, e o texto está no HTML para os motores de busca.
 */
export function FaqBlock({ block }: { block: StoreBlock }) {
    const data = block.data as Data;

    if (data.items.length === 0) {
        return null;
    }

    return (
        <BlockSection style={block.style}>
            <div
                className={cn(
                    'max-w-3xl',
                    block.style.align === 'center' && 'mx-auto',
                )}
            >
                {data.title && (
                    <h2 className="mb-6 text-2xl font-semibold tracking-tight md:text-3xl">
                        {data.title}
                    </h2>
                )}
                <div className="divide-y divide-border border-y border-border text-left">
                    {data.items.map((item, index) => (
                        <details key={index} className="group">
                            <summary className="flex cursor-pointer list-none items-center justify-between gap-4 py-5 text-base font-medium [&::-webkit-details-marker]:hidden">
                                {item.question}
                                <Plus
                                    aria-hidden="true"
                                    className="size-4 shrink-0 text-muted-foreground transition-transform group-open:rotate-45"
                                />
                            </summary>
                            <RichText
                                html={item.answer}
                                className="pb-5 text-muted-foreground"
                            />
                        </details>
                    ))}
                </div>
            </div>
        </BlockSection>
    );
}
