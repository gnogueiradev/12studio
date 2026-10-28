import {
    closestCenter,
    DndContext,
    KeyboardSensor,
    PointerSensor,
    useDroppable,
    useSensor,
    useSensors,
} from '@dnd-kit/core';
import type {
    Announcements,
    DragEndEvent,
    DragOverEvent,
    DragStartEvent,
    UniqueIdentifier,
} from '@dnd-kit/core';
import {
    arrayMove,
    SortableContext,
    sortableKeyboardCoordinates,
    useSortable,
    verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { GripVertical, Lock, Plus, Trash2 } from 'lucide-react';
import { useRef, useState } from 'react';
import type { HTMLAttributes, ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { StoreLink } from '@/types/page-builder';
import {
    destinationLabel,
    filledLocales,
    findItem,
    hasErrors,
    isComplaintsBook,
    labelOf,
} from './menu-model';
import type {
    MenuErrors,
    MenuItem,
    MenuLimits,
    MenuLocation,
} from './menu-model';

/** Itens de topo (ou colunas) e os links por baixo deles. */
type Kind = 'item' | 'child';

/** Prefixo da zona de largar de uma coluna vazia. */
const EMPTY_COLUMN = 'vazia:';

type Props = {
    location: MenuLocation;
    items: MenuItem[];
    setItems: (change: (items: MenuItem[]) => MenuItem[]) => void;
    limits: MenuLimits;
    links: StoreLink[];
    locales: string[];
    selected: string | null;
    errors: MenuErrors;
    onSelect: (uid: string) => void;
    onAddChild: (parentUid: string) => void;
    onRemove: (uid: string) => void;
};

/**
 * A estrutura do menu em árvore. Arrasta-se pela pega ⠿ (rato ou teclado):
 * os itens de topo entre si e os links entre si — no rodapé, também de uma
 * coluna para outra.
 */
export function MenuTree({
    location,
    items,
    setItems,
    limits,
    links,
    locales,
    selected,
    errors,
    onSelect,
    onAddChild,
    onRemove,
}: Props) {
    const [activeKind, setActiveKind] = useState<Kind | null>(null);
    // Para o Escape repor o que um arrasto entre colunas já mexeu.
    const before = useRef<MenuItem[]>(items);
    const isFooter = location === 'footer';
    const locale = locales[0];

    const sensors = useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 4 } }),
        useSensor(KeyboardSensor, {
            coordinateGetter: sortableKeyboardCoordinates,
        }),
    );

    const parentOf = (id: UniqueIdentifier, list: MenuItem[]) => {
        const key = String(id);

        return key.startsWith(EMPTY_COLUMN)
            ? list.find((item) => EMPTY_COLUMN + item.uid === key)
            : list.find((item) =>
                  item.children.some((child) => child.uid === key),
              );
    };

    const nameOf = (id: UniqueIdentifier) => {
        const key = String(id);
        const found = findItem(
            items,
            key.startsWith(EMPTY_COLUMN) ? key.slice(EMPTY_COLUMN.length) : key,
        );

        return found
            ? `“${labelOf(found.item, locale) || 'Sem texto'}”`
            : 'o item';
    };

    const announcements: Announcements = {
        onDragStart: ({ active }) => `Pegaste em ${nameOf(active.id)}.`,
        onDragOver: ({ active, over }) =>
            over
                ? `${nameOf(active.id)} está sobre ${nameOf(over.id)}.`
                : `${nameOf(active.id)} já não está sobre nenhum item.`,
        onDragEnd: ({ active, over }) =>
            over
                ? `${nameOf(active.id)} largado sobre ${nameOf(over.id)}.`
                : `${nameOf(active.id)} largado.`,
        onDragCancel: ({ active }) =>
            `Cancelado. ${nameOf(active.id)} voltou ao sítio.`,
    };

    const onDragStart = ({ active }: DragStartEvent) => {
        before.current = items;
        setActiveKind((active.data.current?.kind as Kind) ?? null);
    };

    // Um link a passar para outra coluna (ou submenu) muda logo de lista,
    // para a nova lista abrir espaço enquanto ainda arrastas.
    const onDragOver = ({ active, over }: DragOverEvent) => {
        if (!over || active.data.current?.kind !== 'child') {
            return;
        }

        setItems((list) => {
            const from = parentOf(active.id, list);
            const to = parentOf(over.id, list);

            if (
                !from ||
                !to ||
                from.uid === to.uid ||
                to.children.length >= limits.children
            ) {
                return list;
            }

            const moving = from.children.find(
                (child) => child.uid === active.id,
            );
            const overIndex = to.children.findIndex(
                (child) => child.uid === over.id,
            );
            const at = overIndex >= 0 ? overIndex : to.children.length;

            if (!moving) {
                return list;
            }

            return list.map((item) => {
                if (item.uid === from.uid) {
                    return {
                        ...item,
                        children: item.children.filter(
                            (child) => child.uid !== moving.uid,
                        ),
                    };
                }

                if (item.uid === to.uid) {
                    return {
                        ...item,
                        children: [
                            ...item.children.slice(0, at),
                            moving,
                            ...item.children.slice(at),
                        ],
                    };
                }

                return item;
            });
        });
    };

    const onDragEnd = ({ active, over }: DragEndEvent) => {
        setActiveKind(null);

        if (!over || active.id === over.id) {
            return;
        }

        setItems((list) => {
            if (active.data.current?.kind === 'item') {
                const from = list.findIndex((item) => item.uid === active.id);
                const to = list.findIndex((item) => item.uid === over.id);

                return from >= 0 && to >= 0 ? arrayMove(list, from, to) : list;
            }

            const parent = parentOf(active.id, list);
            const from =
                parent?.children.findIndex(
                    (child) => child.uid === active.id,
                ) ?? -1;
            const to =
                parent?.children.findIndex((child) => child.uid === over.id) ??
                -1;

            if (!parent || from < 0 || to < 0) {
                return list;
            }

            return list.map((item) =>
                item.uid === parent.uid
                    ? { ...item, children: arrayMove(item.children, from, to) }
                    : item,
            );
        });
    };

    const onDragCancel = () => {
        setActiveKind(null);
        setItems(() => before.current);
    };

    const rowProps = (item: MenuItem, parent: MenuItem | null) => ({
        item,
        locale,
        totalLocales: locales.length,
        filled: filledLocales(item, locales),
        destination: destinationLabel(item, links),
        // A coluna do rodapé pode não ter link; o resto precisa de um.
        targetOptional: isFooter && parent === null,
        selected: selected === item.uid,
        error: hasErrors(errors, item.uid),
        onSelect: () => onSelect(item.uid),
        onRemove: () => onRemove(item.uid),
    });

    return (
        <DndContext
            sensors={sensors}
            collisionDetection={closestCenter}
            accessibility={{
                announcements,
                screenReaderInstructions: {
                    draggable:
                        'Para pegar num item, carrega em espaço ou Enter. Usa as setas para o mover e espaço ou Enter para o largar. Escape cancela.',
                },
            }}
            onDragStart={onDragStart}
            onDragOver={onDragOver}
            onDragEnd={onDragEnd}
            onDragCancel={onDragCancel}
        >
            <SortableContext
                items={items.map((item) => item.uid)}
                strategy={verticalListSortingStrategy}
            >
                <ul className="grid gap-1">
                    {items.map((item) => (
                        <SortableBranch
                            key={item.uid}
                            id={item.uid}
                            kind="item"
                            activeKind={activeKind}
                            render={(handle) => (
                                <>
                                    <TreeRow
                                        {...rowProps(item, null)}
                                        handle={handle}
                                        strong
                                        addLabel={
                                            isFooter ? 'Link' : 'Sub-link'
                                        }
                                        canAdd={
                                            item.children.length <
                                            limits.children
                                        }
                                        onAdd={() => onAddChild(item.uid)}
                                    />
                                    {(isFooter || item.children.length > 0) && (
                                        <SortableContext
                                            items={item.children.map(
                                                (child) => child.uid,
                                            )}
                                            strategy={
                                                verticalListSortingStrategy
                                            }
                                        >
                                            <ul className="mt-1 ml-5 grid gap-1 border-l border-border/60 pl-2">
                                                {item.children.map((child) => (
                                                    <SortableBranch
                                                        key={child.uid}
                                                        id={child.uid}
                                                        kind="child"
                                                        activeKind={activeKind}
                                                        render={(
                                                            childHandle,
                                                        ) => (
                                                            <TreeRow
                                                                {...rowProps(
                                                                    child,
                                                                    item,
                                                                )}
                                                                handle={
                                                                    childHandle
                                                                }
                                                            />
                                                        )}
                                                    />
                                                ))}
                                                {isFooter &&
                                                    item.children.length ===
                                                        0 && (
                                                        <EmptyColumn
                                                            id={
                                                                EMPTY_COLUMN +
                                                                item.uid
                                                            }
                                                            enabled={
                                                                activeKind ===
                                                                'child'
                                                            }
                                                        />
                                                    )}
                                            </ul>
                                        </SortableContext>
                                    )}
                                </>
                            )}
                        />
                    ))}
                </ul>
            </SortableContext>
        </DndContext>
    );
}

type Handle = HTMLAttributes<HTMLButtonElement>;

function SortableBranch({
    id,
    kind,
    activeKind,
    render,
}: {
    id: string;
    kind: Kind;
    activeKind: Kind | null;
    render: (handle: Handle) => ReactNode;
}) {
    const {
        attributes,
        listeners,
        setNodeRef,
        transform,
        transition,
        isDragging,
    } = useSortable({
        id,
        data: { kind },
        // Enquanto arrastas um item de topo, os links não servem de alvo (e
        // vice-versa) — senão um item ia parar dentro de outro.
        disabled: {
            draggable: false,
            droppable: activeKind !== null && activeKind !== kind,
        },
    });

    return (
        <li
            ref={setNodeRef}
            style={{
                transform: CSS.Translate.toString(transform),
                transition,
            }}
            data-dragging={isDragging}
            className="rounded-md data-[dragging=true]:relative data-[dragging=true]:z-10 data-[dragging=true]:bg-card data-[dragging=true]:shadow-lg"
        >
            {render({ ...attributes, ...listeners })}
        </li>
    );
}

function EmptyColumn({ id, enabled }: { id: string; enabled: boolean }) {
    const { setNodeRef, isOver } = useDroppable({ id, disabled: !enabled });

    return (
        <li
            ref={setNodeRef}
            className={cn(
                'rounded-md border border-dashed border-border px-3 py-2 text-xs text-muted-foreground',
                isOver && 'border-ring bg-accent',
            )}
        >
            Sem links. Arrasta um para aqui ou usa “+ Link”.
        </li>
    );
}

function TreeRow({
    item,
    locale,
    totalLocales,
    filled,
    destination,
    targetOptional,
    selected,
    error,
    strong = false,
    handle,
    addLabel,
    canAdd = false,
    onAdd,
    onSelect,
    onRemove,
}: {
    item: MenuItem;
    locale: string;
    totalLocales: number;
    filled: number;
    destination: string | null;
    targetOptional: boolean;
    selected: boolean;
    error: boolean;
    strong?: boolean;
    handle: Handle;
    addLabel?: string;
    canAdd?: boolean;
    onAdd?: () => void;
    onSelect: () => void;
    onRemove: () => void;
}) {
    const text = labelOf(item, locale);
    const name = text || 'Sem texto';

    return (
        <div
            className={cn(
                'group flex items-center gap-1 rounded-md border border-transparent pr-1 transition-colors hover:bg-muted/60',
                selected && 'border-border bg-muted',
                error && 'border-destructive/60',
            )}
        >
            <button
                type="button"
                aria-label={`Mover “${name}”`}
                className="flex h-8 w-6 shrink-0 cursor-grab touch-none items-center justify-center rounded text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none active:cursor-grabbing"
                {...handle}
            >
                <GripVertical className="size-4" />
            </button>
            <button
                type="button"
                aria-current={selected ? 'true' : undefined}
                onClick={onSelect}
                className="flex min-w-0 flex-1 items-center gap-2 rounded py-1.5 text-left text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                {error && (
                    <span className="size-2 shrink-0 rounded-full bg-destructive">
                        <span className="sr-only">Tem erros</span>
                    </span>
                )}
                <span
                    className={cn(
                        'truncate',
                        strong && 'font-medium',
                        !text && 'text-muted-foreground italic',
                    )}
                >
                    {name}
                </span>
                {isComplaintsBook(item) && (
                    <span
                        title="Obrigatório para quem vende em Portugal"
                        className="shrink-0 text-muted-foreground"
                    >
                        <Lock aria-hidden className="size-3.5" />
                        <span className="sr-only">
                            Obrigatório para quem vende em Portugal
                        </span>
                    </span>
                )}
                <span className="ml-auto flex min-w-0 shrink items-center gap-2 pl-1">
                    {text && filled < totalLocales && (
                        <span
                            title={`Tem texto em ${filled} de ${totalLocales} idiomas. Nos outros mostra o português.`}
                            className="hidden shrink-0 text-xs whitespace-nowrap text-muted-foreground sm:inline"
                        >
                            {filled}/{totalLocales} idiomas
                        </span>
                    )}
                    {destination ? (
                        <span className="max-w-32 truncate rounded border border-border/60 bg-background px-1.5 py-0.5 text-xs text-muted-foreground">
                            {destination}
                        </span>
                    ) : (
                        !targetOptional && (
                            <span className="shrink-0 text-xs whitespace-nowrap text-warning">
                                Sem destino
                            </span>
                        )
                    )}
                </span>
            </button>
            <span
                className={cn(
                    'flex shrink-0 items-center',
                    !selected &&
                        'lg:opacity-0 lg:group-focus-within:opacity-100 lg:group-hover:opacity-100',
                )}
            >
                {onAdd && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="h-7 px-2 text-xs"
                        disabled={!canAdd}
                        onClick={onAdd}
                        aria-label={`Acrescentar ${addLabel?.toLowerCase()} em “${name}”`}
                    >
                        <Plus />
                        {addLabel}
                    </Button>
                )}
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="size-7 text-muted-foreground hover:text-destructive"
                    aria-label={`Apagar “${name}”`}
                    onClick={onRemove}
                >
                    <Trash2 />
                </Button>
            </span>
        </div>
    );
}
