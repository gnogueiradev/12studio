import { Head, router, usePage } from '@inertiajs/react';
import { Trash2, Upload } from 'lucide-react';
import { useRef, useState } from 'react';
import { ConfirmDialog } from '@/components/admin/confirm-dialog';
import { MontraNav } from '@/components/admin/montra/montra-nav';
import { PageHeader } from '@/components/admin/page-header';
import { Pagination } from '@/components/admin/pagination';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
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

    return (
        <>
            <Head title="Imagens da montra" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Montra"
                    description="As imagens dos blocos. As fotografias dos produtos ficam em cada produto."
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
                    <p className="py-16 text-center text-sm text-muted-foreground">
                        Ainda não há imagens. JPG, PNG ou WebP até 5 MB, até 10
                        de cada vez.
                    </p>
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
        <li className="flex flex-col overflow-hidden rounded-lg border border-border/60 bg-card">
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
                        placeholder="Texto alternativo"
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
                <Button
                    variant="ghost"
                    size="sm"
                    className="justify-self-start text-destructive"
                    onClick={onDelete}
                >
                    <Trash2 />
                    Apagar
                </Button>
            </div>
        </li>
    );
}

MediaLibrary.layout = {
    breadcrumbs: [{ title: 'Montra', href: '/admin/montra/paginas' }],
};
