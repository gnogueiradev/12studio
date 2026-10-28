import { ResponsiveImage } from '@/components/store/responsive-image';
import { cn } from '@/lib/utils';
import type { BlockLink, ResponsiveImageData, StoreBlock } from '@/types/store';
import { LinkButton } from './link-button';
import { BlockSection } from './section';

type Data = {
    title: string;
    text: string;
    link: BlockLink | null;
    image: ResponsiveImageData | null;
};

export function BannerBlock({ block }: { block: StoreBlock }) {
    const data = block.data as Data;
    const centered = block.style.align === 'center';

    const content = (
        <div
            className={cn(
                'flex flex-col gap-5',
                centered
                    ? 'items-center text-center'
                    : 'md:flex-row md:items-center md:justify-between',
            )}
        >
            <div className={cn('max-w-2xl', centered && 'mx-auto')}>
                <h2 className="text-2xl font-semibold tracking-tight text-balance md:text-3xl">
                    {data.title}
                </h2>
                {data.text && (
                    <p className="mt-3 text-base opacity-80 md:text-lg">
                        {data.text}
                    </p>
                )}
            </div>
            {/* O botão principal serve em todos os fundos: dentro de uma secção
                escura (ou sobre imagem) os tokens invertem e ele fica claro. */}
            <LinkButton link={data.link} />
        </div>
    );

    if (data.image) {
        return (
            <BlockSection style={{ ...block.style, bg: 'default' }}>
                <div className="dark relative isolate overflow-hidden rounded-xl bg-background px-6 py-14 text-foreground md:px-12">
                    <ResponsiveImage
                        image={{ ...data.image, alt: '' }}
                        sizes="(min-width: 1152px) 1152px, 100vw"
                        className="absolute inset-0 -z-10 size-full object-cover"
                    />
                    <div className="absolute inset-0 -z-10 bg-background/65" />
                    {content}
                </div>
            </BlockSection>
        );
    }

    return <BlockSection style={block.style}>{content}</BlockSection>;
}
