import type { StoreBlock } from '@/types/store';
import { BlockSection } from './section';

type Data = { title: string; text: string; category: boolean };

export function CatalogHeaderBlock({
    block,
    first,
    nested,
}: {
    block: StoreBlock;
    first: boolean;
    nested: boolean;
}) {
    const data = block.data as Data;
    const Heading = first || data.category ? 'h1' : 'h2';

    return (
        <BlockSection
            shell={!nested}
            style={block.style}
            // Sem fundo próprio, o título e a grelha são a mesma zona: o
            // espaço de baixo sai, para não somar ao de cima do bloco
            // seguinte e deixar o título a boiar longe dos produtos.
            className={
                block.style.bg === 'default' ? 'pb-0 md:pb-0' : undefined
            }
        >
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
