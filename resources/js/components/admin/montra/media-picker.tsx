import { ImagePlus, Search, Upload, X } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { HttpError, requestJson } from '@/lib/http';
import { cn } from '@/lib/utils';
import { lista, store } from '@/routes/admin/montra/media';
import type { MediaItem } from '@/types/page-builder';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onPick: (media: MediaItem) => void;
};

/**
 * A biblioteca de imagens dentro do editor: escolher uma que já existe ou
 * carregar novas (ficam logo escolhíveis; as versões reduzidas fazem-se em
 * segundo plano).
 */
export function MediaPickerDialog({ open, onOpenChange, onPick }: Props) {
    const [items, setItems] = useState<MediaItem[]>([]);
    const [next, setNext] = useState<number | null>(null);
    const [query, setQuery] = useState('');
    const [loading, setLoading] = useState(false);
    const [uploading, setUploading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const fileInput = useRef<HTMLInputElement>(null);

    const load = useCallback(async (q: string, page = 1) => {
        setLoading(true);

        try {
            const response = await requestJson<{
                data: MediaItem[];
                next: number | null;
            }>(lista({ query: { q, page } }).url);
            setItems((current) =>
                page === 1 ? response.data : [...current, ...response.data],
            );
            setNext(response.next);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        if (!open) {
            return;
        }

        const timer = window.setTimeout(() => load(query), 250);

        return () => window.clearTimeout(timer);
    }, [open, query, load]);

    const upload = async (files: FileList | null) => {
        if (!files || files.length === 0) {
            return;
        }

        const form = new FormData();
        Array.from(files).forEach((file) => form.append('files[]', file));
        setUploading(true);
        setError(null);

        try {
            const response = await requestJson<{ data: MediaItem[] }>(
                store().url,
                'POST',
                form,
            );
            setItems((current) => [...response.data, ...current]);

            if (response.data.length === 1) {
                onPick(response.data[0]);
                onOpenChange(false);
            }
        } catch (exception) {
            setError(
                exception instanceof HttpError
                    ? (Object.values(exception.body.errors ?? {})[0]?.[0] ??
                          exception.message)
                    : 'Não foi possível carregar a imagem.',
            );
        } finally {
            setUploading(false);

            if (fileInput.current) {
                fileInput.current.value = '';
            }
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-hidden sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle>Escolher imagem</DialogTitle>
                    <DialogDescription>
                        JPG, PNG ou WebP até 5 MB. Para fotografias de produto,
                        usa a galeria do próprio produto.
                    </DialogDescription>
                </DialogHeader>
                <div className="flex flex-wrap items-center gap-2">
                    <div className="relative min-w-48 flex-1">
                        <Search
                            aria-hidden
                            className="absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                        />
                        <Input
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            placeholder="Procurar pelo nome"
                            aria-label="Procurar imagens"
                            className="pl-8"
                        />
                    </div>
                    <input
                        ref={fileInput}
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        multiple
                        className="sr-only"
                        onChange={(event) => upload(event.target.files)}
                        aria-label="Carregar imagens"
                    />
                    <Button
                        type="button"
                        onClick={() => fileInput.current?.click()}
                        disabled={uploading}
                    >
                        {uploading ? <Spinner /> : <Upload />}
                        Carregar
                    </Button>
                </div>
                <InputError message={error ?? undefined} />
                <div className="-mx-1 grid max-h-[55vh] grid-cols-3 gap-2 overflow-y-auto p-1 sm:grid-cols-4">
                    {items.map((item) => (
                        <button
                            key={item.id}
                            type="button"
                            onClick={() => {
                                onPick(item);
                                onOpenChange(false);
                            }}
                            className="group overflow-hidden rounded-md border border-border/60 text-left focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        >
                            <img
                                src={item.image.src}
                                alt=""
                                loading="lazy"
                                className="aspect-square w-full object-cover transition-opacity group-hover:opacity-90"
                            />
                            <span className="block truncate px-2 py-1 text-xs text-muted-foreground">
                                {item.name ?? `Imagem ${item.id}`}
                            </span>
                        </button>
                    ))}
                    {!loading && items.length === 0 && (
                        <p className="col-span-full py-8 text-center text-sm text-muted-foreground">
                            {query
                                ? 'Nenhuma imagem com esse nome.'
                                : 'A biblioteca está vazia. Carrega a primeira imagem.'}
                        </p>
                    )}
                </div>
                {next !== null && (
                    <Button
                        variant="outline"
                        onClick={() => load(query, next)}
                        disabled={loading}
                    >
                        {loading && <Spinner />}
                        Mostrar mais
                    </Button>
                )}
            </DialogContent>
        </Dialog>
    );
}

/** O campo de imagem do inspetor: miniatura, trocar e tirar. */
export function ImageField({
    id,
    value,
    preview,
    onChange,
}: {
    id: string;
    value: number | null;
    preview: string | null;
    onChange: (id: number | null, src: string | null) => void;
}) {
    const [open, setOpen] = useState(false);

    return (
        <div className="flex items-center gap-3">
            <button
                id={id}
                type="button"
                onClick={() => setOpen(true)}
                className={cn(
                    'flex size-20 shrink-0 items-center justify-center overflow-hidden rounded-md border border-dashed border-border bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                    value !== null && 'border-solid',
                )}
                aria-label={
                    value === null ? 'Escolher imagem' : 'Trocar imagem'
                }
            >
                {value !== null && preview ? (
                    <img
                        src={preview}
                        alt=""
                        className="size-full object-cover"
                    />
                ) : (
                    <ImagePlus
                        aria-hidden
                        className="size-5 text-muted-foreground"
                    />
                )}
            </button>
            <div className="flex flex-col items-start gap-1">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => setOpen(true)}
                >
                    {value === null ? 'Escolher' : 'Trocar'}
                </Button>
                {value !== null && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() => onChange(null, null)}
                    >
                        <X />
                        Tirar
                    </Button>
                )}
            </div>
            <MediaPickerDialog
                open={open}
                onOpenChange={setOpen}
                onPick={(media) => onChange(media.id, media.image.src)}
            />
        </div>
    );
}
