import { Head, router, usePage } from '@inertiajs/react';
import { ImagePlus, Trash2, Upload } from 'lucide-react';
import { useRef, useState } from 'react';
import { ConfirmDialog } from '@/components/admin/confirm-dialog';
import { MontraNav } from '@/components/admin/montra/montra-nav';
import { PageHeader } from '@/components/admin/page-header';
import { Pagination } from '@/components/admin/pagination';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { destroy, store, update } from '@/routes/admin/montra/media';
import type { MediaItem } from '@/types/page-builder';
import type { Paginated } from '@/types/pagination';

type Props = {
    media: Paginated<MediaItem>;
};

function formatSize(bytes: number | null): string {
    if (bytes === null) {
        return '';
    }

    return bytes >= 1024 * 1024
        ? `${(bytes / 1024 / 1024).toFixed(1)} MB`
        : `${Math.round(bytes / 1024)} KB`;
}

/**
 * Montra → Imagens: a biblioteca das imagens dos blocos, do logotipo e do
 * favicon. Uma imagem que alguma página ainda usa não se apaga.
 */
export default function MediaLibrary({ media }: Props) {
    const fileInput = useRef<HTMLInputElement>(null);
    const [uploading, setUploading] = useState(false);
    const [deleting, setDeleting] = useState<MediaItem | null>(null);
    // Arrastar ficheiros para cima da página: o contador evita que o aviso
    // pisque ao passar por cima dos elementos filhos.
    const dragDepth = useRef(0);
    const [dragging, setDragging] = useState(false);
    const { errors } = usePage().props;
    const fieldErrors = errors as Record<string, string | undefined>;
    const uploadError =
        fieldErrors.files ??
        Object.entries(fieldErrors).find(([key]) =>
            key.startsWith('files.'),
        )?.[1];

    const upload = (files: FileList | null) => {
        if (!files || files.length === 0) {
            return;
        }

        setUploading(true);
        router.post(
            store().url,
            { files: Array.from(files) },
            {
                forceFormData: true,
                preserveScroll: true,
                onFinish: () => {
                    setUploading(false);

                    if (fileInput.current) {
                        fileInput.current.value = '';
                    }
                },
            },
        );
    };

    const hasFiles = (event: React.DragEvent) =>
        Array.from(event.dataTransfer.types).includes('Files');

    return (
        <>
            <Head title="Imagens da montra" />
            <div
                className="relative flex h-full flex-1 flex-col gap-4 p-4"
                onDragEnter={(event) => {
                    if (!hasFiles(event)) {
                        return;
                    }

                    dragDepth.current += 1;
                    setDragging(true);
                }}
                onDragOver={(event) =>
                    hasFiles(event) && event.preventDefault()
                }
                onDragLeave={() => {
                    dragDepth.current = Math.max(0, dragDepth.current - 1);
                    setDragging(dragDepth.current > 0);
                }}
                onDrop={(event) => {
                    if (!hasFiles(event)) {
                        return;
                    }

                    event.preventDefault();
                    dragDepth.current = 0;
                    setDragging(false);

                    if (!uploading) {
                        upload(event.dataTransfer.files);
                    }
                }}
            >
                {dragging && (
                    <div className="pointer-events-none absolute inset-2 z-20 flex flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-primary bg-background/90 text-sm font-medium">
                        <ImagePlus aria-hidden className="size-8" />
                        Larga as imagens para as carregar
                    </div>
                )}
                <PageHeader
                    title="Montra"
                    description="As imagens dos blocos, do logótipo e do favicon. Arrasta-as para esta página ou carrega no botão. As fotografias dos produtos ficam em cada produto."
                >
                    <input
                        ref={fileInput}
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        multiple
                        className="sr-only"
                        aria-label="Carregar imagens"
                        onChange={(event) => upload(event.target.files)}
                    />
                    <Button
                        onClick={() => fileInput.current?.click()}
                        disabled={uploading}
                    >
                        {uploading ? <Spinner /> : <Upload />}
                        Carregar imagens
                    </Button>
                </PageHeader>
                <MontraNav current="media" />
                <InputError message={uploadError} />
                <InputError message={fieldErrors.media} />

                {media.data.length === 0 ? (
                    <button
                        type="button"
                        onClick={() => fileInput.current?.click()}
                        disabled={uploading}
                        className={cn(
                            'flex flex-col items-center justify-center gap-3 rounded-xl border-2 border-dashed border-border px-6 py-20 text-center transition-colors',
                            'hover:border-foreground/40 hover:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                        )}
                    >
                        <span className="flex size-12 items-center justify-center rounded-full bg-muted">
                            {uploading ? (
                                <Spinner className="size-5" />
                            ) : (
                                <ImagePlus aria-hidden className="size-5" />
                            )}
                        </span>
                        <span className="font-medium">
                            Arrasta as imagens para aqui
                        </span>
                        <span className="text-sm text-muted-foreground">
                            ou carrega para escolher do computador. JPG, PNG ou
                            WebP até 5 MB, até 10 de cada vez.
                        </span>
                    </button>
                ) : (
                    <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
                        {media.data.map((item) => (
                            <MediaCard
                                key={item.id}
                                item={item}
                                onDelete={() => setDeleting(item)}
                            />
                        ))}
                    </ul>
                )}
                <Pagination page={media} noun="imagens" />
            </div>
            <ConfirmDialog
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
                title="Apagar esta imagem?"
                description="Sai da biblioteca e do servidor. Se alguma página ou o tema a usar, não se apaga e dizemos-te onde está."
                confirmLabel="Apagar"
                destructive
                onConfirm={() =>
                    deleting &&
                    router.delete(destroy(deleting.id).url, {
                        preserveScroll: true,
                        onFinish: () => setDeleting(null),
                    })
                }
            />
        </>
    );
}

function MediaCard({
    item,
    onDelete,
}: {
    item: MediaItem;
    onDelete: () => void;
}) {
    const [alt, setAlt] = useState(item.alt ?? '');
    const dirty = alt !== (item.alt ?? '');

    return (
        <li className="group relative flex flex-col overflow-hidden rounded-lg border border-border/60 bg-card">
            <Button
                variant="secondary"
                size="icon"
                className="absolute top-2 right-2 z-10 size-8 shadow-sm transition-opacity pointer-fine:opacity-0 pointer-fine:group-focus-within:opacity-100 pointer-fine:group-hover:opacity-100"
                aria-label={`Apagar ${item.name ?? 'imagem'}`}
                onClick={onDelete}
            >
                <Trash2 />
            </Button>
            <img
                src={item.image.src}
                srcSet={item.image.srcset ?? undefined}
                sizes="(min-width: 1280px) 16vw, (min-width: 640px) 33vw, 50vw"
                alt={item.alt ?? ''}
                loading="lazy"
                className="aspect-square w-full object-cover"
            />
            <div className="grid gap-2 p-2">
                <p
                    className="truncate text-xs text-muted-foreground"
                    title={item.name ?? undefined}
                >
                    {item.name}
                    {item.width && item.height
                        ? ` · ${item.width}×${item.height}`
                        : ''}
                    {item.size ? ` · ${formatSize(item.size)}` : ''}
                </p>
                <form
                    className="flex gap-1"
                    onSubmit={(event) => {
                        event.preventDefault();
                        router.patch(
                            update(item.id).url,
                            { alt },
                            { preserveScroll: true },
                        );
                    }}
                >
                    <Input
                        value={alt}
                        maxLength={160}
                        placeholder="Descreve a imagem"
                        aria-label={`Texto alternativo de ${item.name ?? 'imagem'}`}
                        className="h-8 text-xs"
                        onChange={(event) => setAlt(event.target.value)}
                    />
                    {dirty && (
                        <Button type="submit" size="sm" className="h-8">
                            OK
                        </Button>
                    )}
                </form>
            </div>
        </li>
    );
}

MediaLibrary.layout = {
    breadcrumbs: [{ title: 'Montra', href: '/admin/montra/paginas' }],
};
