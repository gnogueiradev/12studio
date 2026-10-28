import { cn } from '@/lib/utils';
import type { StoreBlock } from '@/types/store';
import { BlockIcon } from './icons';
import { BlockSection } from './section';

type Data = { items: { icon: string; title: string; text: string }[] };

export function TrustBlock({ block }: { block: StoreBlock }) {
    const data = block.data as Data;

    if (data.items.length === 0) {
        return null;
    }

    return (
        <BlockSection style={block.style}>
            <ul
                className={cn(
                    'grid grid-cols-2 gap-6 border-y border-border/60 py-8 md:gap-8',
                    data.items.length >= 4
                        ? 'md:grid-cols-4'
                        : 'md:grid-cols-3',
                )}
            >
                {data.items.map((item, index) => (
                    <li
                        key={index}
                        className={cn(
                            'flex flex-col gap-2',
                            block.style.align === 'center'
                                ? 'items-center text-center'
                                : 'items-start',
                        )}
                    >
                        <BlockIcon
                            name={item.icon}
                            className="size-5 text-gold"
                        />
                        <p className="text-sm font-medium">{item.title}</p>
                        {item.text && (
                            <p className="text-xs text-muted-foreground">
                                {item.text}
                            </p>
                        )}
                    </li>
                ))}
            </ul>
        </BlockSection>
    );
}
