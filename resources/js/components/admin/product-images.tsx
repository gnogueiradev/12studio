import { router, useForm } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Star, Trash2, Upload } from 'lucide-react';
import { useRef, useState } from 'react';
import { ConfirmDialog } from '@/components/admin/confirm-dialog';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { destroy, principal, update } from '@/routes/admin/imagens';
import { ordem, store } from '@/routes/admin/produtos/imagens';
import type { ProductImageRow } from '@/types/catalog';

type Props = {
    productId: number;
    images: ProductImageRow[];
};

const MAX_PER_UPLOAD = 10;

export function ProductImages({ productId, images }: Props) {
    const fileInput = useRef<HTMLInputElement>(null);
    const [dragging, setDragging] = useState(false);
    const [deleting, setDeleting] = useState<ProductImageRow | null>(null);

    const { setData, post, processing, errors, reset } = useForm<{
        images: File[];
    }>({ images: [] });

    const upload = (files: FileList | null) => {
        if (!files || files.length === 0) {
            return;
        }

        setData('images', Array.from(files).slice(0, MAX_PER_UPLOAD));

        post(store(productId).url, {
            forceFormData: true,
            /*
             * A galeria vive dentro do modal de edição do produto, e o servidor
             * responde com um `back()` — sem preservar o estado, cada foto
             * carregada fechava o modal e obrigava a reabri-lo.
             */
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => reset(),
            onFinish: () => {
                if (fileInput.current) {
                    fileInput.current.value = '';
                }
            },
        });
    };

    /**
     * Reordenar troca com o vizinho e reenvia a lista inteira. Arrastar-e-
     * largar exigia o dnd-kit — dependência a mais para uma galeria de meia
     * dúzia de fotos.
     */
    const move = (index: number, direction: -1 | 1) => {
        const target = index + direction;

        if (target < 0 || target >= images.length) {
            return;
        }

        const reordered = [...images];
        [reordered[index], reordered[target]] = [
            reordered[target],
            reordered[index],
        ];

        router.patch(
            ordem(productId).url,
            { ids: reordered.map((image) => image.id) },
            { preserveScroll: true, preserveState: true },
        );
    };

    return (
        <div className="flex flex-col gap-4">
            <label
                onDragOver={(event) => {
                    event.preventDefault();
                    setDragging(true);
                }}
                onDragLeave={() => setDragging(false)}
                onDrop={(event) => {
                    event.preventDefault();
                    setDragging(false);
                    upload(event.dataTransfer.files);
                }}
                className={`flex cursor-pointer flex-col items-center gap-2 rounded-xl border border-dashed p-8 text-center transition-colors ${
                    dragging
                        ? 'border-foreground/40 bg-accent'
                        : 'border-border hover:border-foreground/30'
                }`}
            >
                {processing ? (
                    <Spinner />
                ) : (
                    <Upload className="size-5 text-muted-foreground" />
                )}
                <span className="text-sm font-medium">
                    Larga as fotografias aqui ou clica para escolher
                </span>
                <span className="text-xs text-muted-foreground">
                    JPG, PNG ou WEBP · até 5 MB cada · máximo {MAX_PER_UPLOAD}{' '}
                    de cada vez
                </span>
                <input
                    ref={fileInput}
                    type="file"
                    accept="image/jpeg,image/png,image/webp"
                    multiple
                    className="sr-only"
                    onChange={(event) => upload(event.target.files)}
                />
            </label>

            <InputError message={errors.images} />

            {images.length > 0 && (
                <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                    {images.map((image, index) => (
                        <ImageCard
                            key={image.id}
                            image={image}
                            first={index === 0}
                            last={index === images.length - 1}
                            onMove={(direction) => move(index, direction)}
                            onDelete={() => setDeleting(image)}
                        />
                    ))}
                </div>
            )}

            <ConfirmDialog
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
                title="Apagar fotografia"
                description={
                    deleting?.isPrimary ? (
                        <>
                            Esta é a fotografia <strong>principal</strong>. O
                            ficheiro é mesmo apagado e a seguinte assume o
                            lugar.
                        </>
                    ) : (
                        'O ficheiro é mesmo apagado do disco — isto não se desfaz.'
                    )
                }
                confirmLabel="Apagar"
                destructive
                onConfirm={() => {
                    if (deleting) {
                        router.delete(destroy(deleting.id).url, {
                            preserveScroll: true,
                            preserveState: true,
                            onFinish: () => setDeleting(null),
                        });
                    }
                }}
            />
        </div>
    );
}

type CardProps = {
    image: ProductImageRow;
    first: boolean;
    last: boolean;
    onMove: (direction: -1 | 1) => void;
    onDelete: () => void;
};

function ImageCard({ image, first, last, onMove, onDelete }: CardProps) {
    const [alt, setAlt] = useState(image.alt ?? '');

    const saveAlt = () => {
        if (alt === (image.alt ?? '')) {
            return;
        }

        router.patch(
            update(image.id).url,
            { alt },
            { preserveScroll: true, preserveState: true },
        );
    };

    return (
        <div className="flex flex-col gap-2 rounded-xl border border-border/60 p-2">
            <div className="relative aspect-square overflow-hidden rounded-lg bg-muted">
                <img
                    src={image.url}
                    alt={image.alt ?? ''}
                    className="size-full object-cover"
                />
                {image.isPrimary && (
                    <Badge className="absolute top-2 left-2">Principal</Badge>
                )}
            </div>

            <Input
                value={alt}
                onChange={(event) => setAlt(event.target.value)}
                onBlur={saveAlt}
                placeholder="Texto alternativo"
                maxLength={160}
                className="h-8 text-xs"
                aria-label="Texto alternativo"
            />

            <div className="flex items-center justify-between gap-1">
                <div className="flex gap-0.5">
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-7"
                        disabled={first}
                        onClick={() => onMove(-1)}
                        aria-label="Mover para trás"
                    >
                        <ChevronLeft className="size-4" />
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-7"
                        disabled={last}
                        onClick={() => onMove(1)}
                        aria-label="Mover para a frente"
                    >
                        <ChevronRight className="size-4" />
                    </Button>
                </div>

                <div className="flex gap-0.5">
                    {!image.isPrimary && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="size-7"
                            onClick={() =>
                                router.patch(
                                    principal(image.id).url,
                                    {},
                                    {
                                        preserveScroll: true,
                                        preserveState: true,
                                    },
                                )
                            }
                            aria-label="Marcar como principal"
                            title="Marcar como principal"
                        >
                            <Star className="size-4" />
                        </Button>
                    )}
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-7"
                        onClick={onDelete}
                        aria-label="Apagar fotografia"
                    >
                        <Trash2 className="size-4" />
                    </Button>
                </div>
            </div>
        </div>
    );
}
