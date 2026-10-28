import {
    closestCenter,
    DndContext,
    KeyboardSensor,
    PointerSensor,
    useSensor,
    useSensors,
} from '@dnd-kit/core';
import type { DragEndEvent } from '@dnd-kit/core';
import {
    arrayMove,
    SortableContext,
    sortableKeyboardCoordinates,
    useSortable,
    verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { GripVertical, Package, Plus, Search, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { requestJson } from '@/lib/http';
import { produtos } from '@/routes/admin/montra/opcoes';
import type { ProductOption } from '@/types/page-builder';

type Props = {
    id: string;
    value: number[];
    max?: number;
    known: Record<number, ProductOption>;
    onKnown: (product: ProductOption) => void;
    onChange: (ids: number[]) => void;
};

/**
 * A escolha manual de produtos de uma grelha: pesquisa por nome e a ordem
 * por drag and drop (ou teclado: espaço para pegar, setas, espaço para
 * largar).
 */
export function ProductPicker({
    id,
    value,
    max = 24,
    known,
    onKnown,
    onChange,
}: Props) {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<ProductOption[]>([]);
    const sensors = useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 4 } }),
        useSensor(KeyboardSensor, {
            coordinateGetter: sortableKeyboardCoordinates,
        }),
    );

    useEffect(() => {
        if (query.trim() === '') {
            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            requestJson<{ data: ProductOption[] }>(
                produtos({ query: { q: query } }).url,
                'GET',
                undefined,
                controller.signal,
            )
                .then((response) => setResults(response.data))
                .catch(() => undefined);
        }, 250);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [query]);

    const shown = query.trim() === '' ? [] : results;

    const add = (product: ProductOption) => {
        onKnown(product);

        if (!value.includes(product.id) && value.length < max) {
            onChange([...value, product.id]);
        }
    };

    const onDragEnd = ({ active, over }: DragEndEvent) => {
        if (over && active.id !== over.id) {
            onChange(
                arrayMove(
                    value,
                    value.indexOf(Number(active.id)),
                    value.indexOf(Number(over.id)),
                ),
            );
        }
    };

    return (
        <div className="grid gap-2">
            <div className="relative">
                <Search
                    aria-hidden
                    className="absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    id={id}
                    value={query}
                    onChange={(event) => setQuery(event.target.value)}
                    placeholder="Procurar produto para juntar"
                    className="pl-8"
                />
            </div>
            {shown.length > 0 && (
                <ul className="max-h-48 overflow-y-auto rounded-md border border-border/60">
                    {shown.map((product) => (
                        <li key={product.id}>
                            <button
                                type="button"
                                onClick={() => add(product)}
                                disabled={
                                    value.includes(product.id) ||
                                    value.length >= max
                                }
                                className="flex w-full items-center gap-2 px-2 py-1.5 text-left text-sm hover:bg-muted disabled:opacity-50"
                            >
                                <Thumb product={product} />
                                <span className="min-w-0 flex-1 truncate">
                                    {product.name}
                                </span>
                                {product.status !== 'active' && (
                                    <span className="text-xs text-muted-foreground">
                                        rascunho
                                    </span>
                                )}
                                <Plus aria-hidden className="size-4" />
                            </button>
                        </li>
                    ))}
                </ul>
            )}
            {value.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    Ainda nenhum produto escolhido.
                </p>
            ) : (
                <DndContext
                    sensors={sensors}
                    collisionDetection={closestCenter}
                    onDragEnd={onDragEnd}
                >
                    <SortableContext
                        items={value}
                        strategy={verticalListSortingStrategy}
                    >
                        <ul className="grid gap-1">
                            {value.map((productId) => (
                                <SortableProduct
                                    key={productId}
                                    id={productId}
                                    product={known[productId]}
                                    onRemove={() =>
                                        onChange(
                                            value.filter(
                                                (item) => item !== productId,
                                            ),
                                        )
                                    }
                                />
                            ))}
                        </ul>
                    </SortableContext>
                </DndContext>
            )}
            <p className="text-xs text-muted-foreground">
                {value.length} de {max}. Os que não estão à venda não aparecem
                no site.
            </p>
        </div>
    );
}

function SortableProduct({
    id,
    product,
    onRemove,
}: {
    id: number;
    product: ProductOption | undefined;
    onRemove: () => void;
}) {
    const {
        attributes,
        listeners,
        setNodeRef,
        transform,
        transition,
        isDragging,
    } = useSortable({ id });

    return (
        <li
            ref={setNodeRef}
            style={{
                transform: CSS.Transform.toString(transform),
                transition,
            }}
            className="flex items-center gap-2 rounded-md border border-border/60 bg-card px-1.5 py-1 text-sm data-[dragging=true]:shadow-md"
            data-dragging={isDragging}
        >
            <button
                type="button"
                className="cursor-grab touch-none rounded p-1 text-muted-foreground hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                aria-label={`Mover ${product?.name ?? 'produto'}`}
                {...attributes}
                {...listeners}
            >
                <GripVertical className="size-4" />
            </button>
            {product && <Thumb product={product} />}
            <span className="min-w-0 flex-1 truncate">
                {product?.name ?? `Produto #${id}`}
            </span>
            <Button
                type="button"
                variant="ghost"
                size="icon"
                className="size-7"
                aria-label={`Tirar ${product?.name ?? 'produto'}`}
                onClick={onRemove}
            >
                <X />
            </Button>
        </li>
    );
}

function Thumb({ product }: { product: ProductOption }) {
    return product.image ? (
        <img
            src={product.image}
            alt=""
            className="size-7 shrink-0 rounded object-cover"
        />
    ) : (
        <span className="flex size-7 shrink-0 items-center justify-center rounded bg-muted">
            <Package aria-hidden className="size-3.5 text-muted-foreground" />
        </span>
    );
}
