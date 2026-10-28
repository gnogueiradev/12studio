import { ResponsiveImage } from '@/components/store/responsive-image';
import { cn } from '@/lib/utils';
import type { BlockLink, ResponsiveImageData, StoreBlock } from '@/types/store';
import { LinkButton } from './link-button';
import { BlockSection } from './section';

type Data = {
    eyebrow: string;
    title: string;
    subtitle: string;
    primary: BlockLink | null;
    secondary: BlockLink | null;
    image: ResponsiveImageData | null;
};

const RISE_IN =
    'ease-[cubic-bezier(0.2,0.7,0.2,1)] fill-mode-both motion-safe:animate-in motion-safe:fade-in motion-safe:slide-in-from-bottom-4 duration-700';

export function HeroBlock({
    block,
    first,
}: {
    block: StoreBlock;
    first: boolean;
}) {
    const data = block.data as Data;
    const variant = block.variant ?? 'split';
    const centered = variant === 'centered' || block.style.align === 'center';

    // O primeiro bloco da página leva o <h1>; os outros heros, <h2>.
    const Heading = first ? 'h1' : 'h2';

    const text = (
        <div
            className={cn(
                'flex max-w-2xl flex-col gap-5',
                RISE_IN,
                centered && 'mx-auto items-center text-center',
            )}
        >
            {data.eyebrow && (
                <p className="text-xs font-medium tracking-[0.2em] text-gold-text uppercase">
                    {data.eyebrow}
                </p>
            )}
            <Heading className="text-4xl leading-[1.05] font-semibold tracking-tight text-balance md:text-5xl lg:text-6xl">
                {data.title}
            </Heading>
            {data.subtitle && (
                <p className="text-lg text-pretty text-muted-foreground md:text-xl">
                    {data.subtitle}
                </p>
            )}
            {(data.primary || data.secondary) && (
                <div
                    className={cn(
                        'mt-2 flex flex-wrap gap-3',
                        centered && 'justify-center',
                    )}
                >
                    <LinkButton link={data.primary} />
                    <LinkButton link={data.secondary} variant="outline" />
                </div>
            )}
        </div>
    );

    if (variant === 'background' && data.image) {
        return (
            <section className="dark relative isolate overflow-hidden bg-background text-foreground">
                <ResponsiveImage
                    image={data.image}
                    sizes="100vw"
                    priority={first}
                    className="absolute inset-0 -z-10 size-full object-cover"
                />
                <div className="absolute inset-0 -z-10 bg-background/60" />
                <div className="mx-auto flex min-h-[70vh] w-full max-w-6xl items-center px-4 py-24">
                    {text}
                </div>
            </section>
        );
    }

    if (variant === 'split' && data.image) {
        return (
            <BlockSection style={block.style}>
                <div className="grid items-center gap-10 md:grid-cols-2 md:gap-16">
                    {text}
                    <div
                        className={cn(
                            'aspect-[4/5] overflow-hidden rounded-xl bg-muted md:aspect-square',
                            RISE_IN,
                            'delay-150',
                        )}
                    >
                        <ResponsiveImage
                            image={data.image}
                            sizes="(min-width: 768px) 50vw, 100vw"
                            priority={first}
                            className="size-full object-cover"
                        />
                    </div>
                </div>
            </BlockSection>
        );
    }

    return <BlockSection style={block.style}>{text}</BlockSection>;
}
