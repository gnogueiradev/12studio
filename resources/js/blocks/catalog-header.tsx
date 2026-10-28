import type { StoreBlock } from '@/types/store';
import { BlockSection } from './section';

type Data = { title: string; text: string; category: boolean };

export function CatalogHeaderBlock({
    block,
    first,
}: {
    block: StoreBlock;
    first: boolean;
}) {
    const data = block.data as Data;
    const Heading = first || data.category ? 'h1' : 'h2';

    return (
        <BlockSection style={block.style}>
            <div className="max-w-2xl">
                <Heading className="text-3xl font-semibold tracking-tight text-balance md:text-4xl">
                    {data.title}
                </Heading>
                {data.text && (
                    <p className="mt-3 text-base text-pretty text-muted-foreground md:text-lg">
                        {data.text}
                    </p>
                )}
            </div>
        </BlockSection>
    );
}
