import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useState } from 'react';
import { ResponsiveImage } from '@/components/store/responsive-image';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { ResponsiveImageData, StoreBlock } from '@/types/store';
import { BlockHeading, BlockSection } from './section';

type Data = { title: string; images: { image: ResponsiveImageData | null }[] };

export function GalleryBlock({ block }: { block: StoreBlock }) {
    const data = block.data as Data;
    const t = useT();
    const images = data.images
        .map((item) => item.image)
        .filter((image): image is ResponsiveImageData => image !== null);
    const [open, setOpen] = useState<number | null>(null);

    if (images.length === 0) {
        return null;
    }

    const go = (step: number) =>
        setOpen((current) =>
            current === null
                ? null
                : (current + step + images.length) % images.length,
        );

    const carousel = block.variant === 'carousel';

    return (
        <BlockSection style={block.style}>
            <BlockHeading title={data.title} align={block.style.align} />
            <ul
                className={cn(
                    carousel
                        ? '-mx-4 flex snap-x snap-mandatory gap-4 overflow-x-auto px-4 pb-2'
                        : 'grid grid-cols-2 gap-3 md:grid-cols-3 md:gap-4',
                )}
            >
                {images.map((image, index) => (
                    <li
                        key={image.src + index}
                        className={cn(
                            carousel &&
                                'w-[80%] shrink-0 snap-start sm:w-[45%] lg:w-[32%]',
                        )}
                    >
                        <button
                            type="button"
                            onClick={() => setOpen(index)}
                            className="block aspect-square w-full overflow-hidden rounded-lg bg-muted"
                            aria-label={t('gallery.open', {
                                n: index + 1,
                                total: images.length,
                            })}
                        >
                            <ResponsiveImage
                                image={image}
                                sizes="(min-width: 768px) 33vw, 50vw"
                                className="size-full object-cover transition-transform duration-500 hover:scale-[1.03]"
                            />
                        </button>
                    </li>
                ))}
            </ul>

            <Dialog
                open={open !== null}
                onOpenChange={(value) => !value && setOpen(null)}
            >
                <DialogContent
                    className="store-theme max-w-[min(96vw,1200px)] border-0 bg-background p-2 sm:max-w-[min(96vw,1200px)]"
                    onKeyDown={(event) => {
                        if (event.key === 'ArrowRight') {
                            go(1);
                        }

                        if (event.key === 'ArrowLeft') {
                            go(-1);
                        }
                    }}
                >
                    <DialogTitle className="sr-only">
                        {data.title || t('gallery.title')}
                    </DialogTitle>
                    {open !== null && (
                        <div className="relative">
                            <ResponsiveImage
                                image={images[open]}
                                sizes="96vw"
                                priority
                                className="max-h-[85vh] w-full rounded-md object-contain"
                            />
                            {images.length > 1 && (
                                <>
                                    <Button
                                        variant="secondary"
                                        size="icon"
                                        className="absolute top-1/2 left-2 -translate-y-1/2 rounded-full"
                                        onClick={() => go(-1)}
                                        aria-label={t('gallery.previous')}
                                    >
                                        <ChevronLeft aria-hidden="true" />
                                    </Button>
                                    <Button
                                        variant="secondary"
                                        size="icon"
                                        className="absolute top-1/2 right-2 -translate-y-1/2 rounded-full"
                                        onClick={() => go(1)}
                                        aria-label={t('gallery.next')}
                                    >
                                        <ChevronRight aria-hidden="true" />
                                    </Button>
                                </>
                            )}
                        </div>
                    )}
                </DialogContent>
            </Dialog>
        </BlockSection>
    );
}
