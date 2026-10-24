import { Quote } from 'lucide-react';
import type { StoreBlock } from '@/types/store';
import { BlockHeading, BlockSection } from './section';

type Data = {
    title: string;
    items: { quote: string; name: string; detail: string }[];
};

export function TestimonialsBlock({ block }: { block: StoreBlock }) {
    const data = block.data as Data;

    if (data.items.length === 0) {
        return null;
    }

    return (
        <BlockSection style={block.style}>
            <BlockHeading title={data.title} align={block.style.align} />
            <ul className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                {data.items.map((item, index) => (
                    <li
                        key={index}
                        className="flex flex-col gap-4 rounded-xl border border-border/60 bg-card p-6 text-left text-card-foreground"
                    >
                        <Quote
                            aria-hidden="true"
                            className="size-5 text-gold"
                        />
                        <blockquote className="text-base leading-relaxed">
                            {item.quote}
                        </blockquote>
                        <p className="mt-auto text-sm">
                            <span className="font-medium">{item.name}</span>
                            {item.detail && (
                                <span className="text-muted-foreground">
                                    {' '}
                                    · {item.detail}
                                </span>
                            )}
                        </p>
                    </li>
                ))}
            </ul>
        </BlockSection>
    );
}
