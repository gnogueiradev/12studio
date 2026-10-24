import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { ResponsiveImage } from '@/components/store/responsive-image';
import { cn } from '@/lib/utils';
import type { ResponsiveImageData, StoreBlock } from '@/types/store';
import { BlockHeading, BlockSection } from './section';

type Category = {
    name: string;
    description: string | null;
    href: string;
    image: ResponsiveImageData | null;
};

type Data = { title: string; subtitle: string; categories: Category[] };

function CategoryTile({
    category,
    className,
}: {
    category: Category;
    className?: string;
}) {
    return (
        <Link
            href={category.href}
            className={cn(
                'group relative flex aspect-[4/3] items-end overflow-hidden rounded-lg bg-muted p-5 text-left',
                className,
            )}
        >
            {category.image && (
                <>
                    <ResponsiveImage
                        image={{ ...category.image, alt: '' }}
                        sizes="(min-width: 768px) 33vw, 100vw"
                        className="absolute inset-0 size-full object-cover transition-transform duration-500 group-hover:scale-[1.04]"
                    />
                    <div className="absolute inset-0 bg-gradient-to-t from-foreground/70 via-foreground/10 to-transparent" />
                </>
            )}
            <span
                className={cn(
                    'relative flex items-center gap-2 text-lg font-semibold',
                    category.image ? 'text-background' : 'text-foreground',
                )}
            >
                {category.name}
                <ArrowRight
                    aria-hidden="true"
                    className="size-4 transition-transform group-hover:translate-x-1"
                />
            </span>
        </Link>
    );
}

export function CategoriesBlock({ block }: { block: StoreBlock }) {
    const data = block.data as Data;

    if (data.categories.length === 0) {
        return null;
    }

    return (
        <BlockSection style={block.style}>
            <BlockHeading
                title={data.title}
                subtitle={data.subtitle}
                align={block.style.align}
            />
            {block.variant === 'carousel' ? (
                <ul className="-mx-4 flex snap-x snap-mandatory gap-4 overflow-x-auto px-4 pb-2">
                    {data.categories.map((category) => (
                        <li
                            key={category.href}
                            className="w-[75%] shrink-0 snap-start sm:w-[45%] lg:w-[30%]"
                        >
                            <CategoryTile category={category} />
                        </li>
                    ))}
                </ul>
            ) : (
                <ul
                    className={cn(
                        'grid grid-cols-1 gap-4 sm:grid-cols-2',
                        // Quatro (ou oito) enchem linhas de quatro; o resto,
                        // de três — sem uma categoria sozinha na última.
                        data.categories.length % 4 === 0
                            ? 'lg:grid-cols-4'
                            : 'lg:grid-cols-3',
                    )}
                >
                    {data.categories.map((category) => (
                        <li key={category.href}>
                            <CategoryTile category={category} />
                        </li>
                    ))}
                </ul>
            )}
        </BlockSection>
    );
}
