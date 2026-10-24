import { cn } from '@/lib/utils';
import type { StoreBlock } from '@/types/store';
import { BlockIcon } from './icons';
import { BlockHeading, BlockSection } from './section';

type Data = {
    title: string;
    subtitle: string;
    steps: { icon: string; title: string; text: string }[];
};

export function StepsBlock({ block }: { block: StoreBlock }) {
    const data = block.data as Data;

    if (data.steps.length === 0) {
        return null;
    }

    return (
        <BlockSection style={block.style}>
            <BlockHeading
                title={data.title}
                subtitle={data.subtitle}
                align={block.style.align}
            />
            <ol
                className={cn(
                    'grid gap-8 md:gap-10',
                    data.steps.length >= 3
                        ? 'md:grid-cols-3'
                        : 'md:grid-cols-2',
                    data.steps.length === 4 && 'lg:grid-cols-4',
                )}
            >
                {data.steps.map((step, index) => (
                    <li
                        key={index}
                        className={cn(
                            'flex flex-col gap-3',
                            block.style.align === 'center' && 'items-center',
                        )}
                    >
                        <span className="relative flex size-12 items-center justify-center rounded-full bg-background text-foreground shadow-sm">
                            <BlockIcon name={step.icon} className="size-5" />
                            <span className="absolute -top-1 -right-1 flex size-5 items-center justify-center rounded-full bg-gold text-[11px] font-semibold text-gold-foreground">
                                {index + 1}
                            </span>
                        </span>
                        <h3 className="text-lg font-semibold">{step.title}</h3>
                        {step.text && (
                            <p className="max-w-xs text-sm text-muted-foreground">
                                {step.text}
                            </p>
                        )}
                    </li>
                ))}
            </ol>
        </BlockSection>
    );
}
