import { Upload, X } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

/** O limite do `StoreProductRequest`: dez ficheiros por pedido. */
const MAX_PHOTOS = 10;

type StagedProps = {
    files: File[];
    onChange: (files: File[]) => void;
    error?: string;
};

/**
 * As fotografias de um produto que ainda não existe.
 *
 * Não há para onde as enviar — `ImageService::store` precisa de um `Product`, e
 * o índice parcial `product_images_one_primary_per_product` exige que a
 * primeira de um produto seja a principal. Por isso ficam em memória e viajam
 * no mesmo POST que cria o produto: ou nasce com as fotos, ou não nasce.
 *
 * A ordem é a que se vê — a primeira da lista é a que fica principal, e
 * remover a primeira promove a seguinte, exactamente como o servidor faz.
 */
export function StagedPhotos({ files, onChange, error }: StagedProps) {
    const fileInput = useRef<HTMLInputElement>(null);
    const [dragging, setDragging] = useState(false);

    /*
     * Um object URL por ficheiro, revogado sempre que a lista muda e no
     * desmonte. Sem o revoke, cada foto largada e retirada deixava o blob
     * agarrado à memória do separador até ele fechar.
     */
    const previews = useMemo(
        () => files.map((file) => URL.createObjectURL(file)),
        [files],
    );

    useEffect(
        () => () => previews.forEach((url) => URL.revokeObjectURL(url)),
        [previews],
    );

    const add = (incoming: FileList | null) => {
        if (!incoming || incoming.length === 0) {
            return;
        }

        onChange([...files, ...Array.from(incoming)].slice(0, MAX_PHOTOS));

        if (fileInput.current) {
            fileInput.current.value = '';
        }
    };

    const full = files.length >= MAX_PHOTOS;

    return (
        <div className="flex flex-col gap-3">
            <label
                onDragOver={(event) => {
                    event.preventDefault();
                    setDragging(true);
                }}
                onDragLeave={() => setDragging(false)}
                onDrop={(event) => {
                    event.preventDefault();
                    setDragging(false);
                    add(event.dataTransfer.files);
                }}
                className={cn(
                    'flex flex-col items-center gap-1.5 rounded-xl border border-dashed p-6 text-center transition-colors',
                    full
                        ? 'cursor-not-allowed border-border opacity-60'
                        : 'cursor-pointer',
                    dragging
                        ? 'border-foreground/40 bg-accent'
                        : 'border-border hover:border-foreground/30',
                )}
            >
                <Upload className="size-5 text-muted-foreground" />
                <span className="text-sm font-medium">
                    {full
                        ? `Máximo de ${MAX_PHOTOS} fotografias`
                        : 'Larga as fotografias aqui ou clica para escolher'}
                </span>
                <span className="text-xs text-muted-foreground">
                    JPG, PNG ou WEBP · até 5 MB cada · o texto alternativo
                    escreve-se depois de o produto existir
                </span>
                <input
                    ref={fileInput}
                    type="file"
                    accept="image/jpeg,image/png,image/webp"
                    multiple
                    disabled={full}
                    className="sr-only"
                    onChange={(event) => add(event.target.files)}
                />
            </label>

            <InputError message={error} />

            {files.length > 0 && (
                <div className="grid grid-cols-3 gap-3 sm:grid-cols-5">
                    {files.map((file, index) => (
                        <div
                            key={`${file.name}-${file.lastModified}-${index}`}
                            className="relative aspect-square overflow-hidden rounded-lg border border-border/60 bg-muted"
                        >
                            <img
                                src={previews[index]}
                                alt=""
                                className="size-full object-cover"
                            />
                            {index === 0 && (
                                <Badge className="absolute top-1 left-1 text-[10px]">
                                    Principal
                                </Badge>
                            )}
                            <button
                                type="button"
                                onClick={() =>
                                    onChange(
                                        files.filter(
                                            (_, position) => position !== index,
                                        ),
                                    )
                                }
                                aria-label={`Retirar ${file.name}`}
                                className="absolute top-1 right-1 grid size-6 place-items-center rounded-full bg-background/90 text-muted-foreground transition-colors hover:text-foreground"
                            >
                                <X className="size-3.5" />
                            </button>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}
