import {
    closestCenter,
    DndContext,
    DragOverlay,
    KeyboardSensor,
    PointerSensor,
    useSensor,
    useSensors,
} from '@dnd-kit/core';
import type {
    Announcements,
    DragEndEvent,
    DragMoveEvent,
    DragOverEvent,
    DragStartEvent,
    KeyboardCoordinateGetter,
} from '@dnd-kit/core';
import {
    SortableContext,
    sortableKeyboardCoordinates,
    useSortable,
    verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import {
    ChevronDown,
    ChevronRight,
    ClipboardPaste,
    Columns3,
    Copy,
    CopyPlus,
    Eye,
    EyeOff,
    GripVertical,
    MoreHorizontal,
    Trash2,
    X,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { HTMLAttributes } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import {
    canDrop,
    erroredNodeIds,
    findNode,
    isEditorContainer,
    nodePath,
} from '@/lib/page-tree';
import type { NodeTarget, SchemaByType, TreeLimits } from '@/lib/page-tree';
import { cn } from '@/lib/utils';
import type { EditorNode } from '@/types/page-builder';
import { BlockIcon, nodeLabel, subtreeFlags } from '../blocks';
import { flatten, project } from './tree-projection';
import type { Row } from './tree-projection';

/** Os píxeis de um nível da árvore. */
const INDENT = 14;
/** Quanto arrastar para o lado muda um nível: um arrasto torto não o faz. */
const LEVEL_STEP = 28;

/** As setas para os lados mudam de nível; para cima e baixo, de posição. */
const coordinates: KeyboardCoordinateGetter = (event, args) => {
    if (event.code === 'ArrowLeft' || event.code === 'ArrowRight') {
        event.preventDefault();

        return {
            ...args.currentCoordinates,
            x:
                args.currentCoordinates.x +
                (event.code === 'ArrowLeft' ? -LEVEL_STEP : LEVEL_STEP),
        };
    }

    return sortableKeyboardCoordinates(event, args);
};

export type PasteWhere = 'inside' | 'after';

type Props = {
    nodes: EditorNode[];
    selected: string | null;
    schema: SchemaByType;
    limits: TreeLimits;
    errors: Record<string, string>;
    onSelect: (id: string) => void;
    onMove: (id: string, target: NodeTarget) => void;
    onToggle: (id: string) => void;
    onRename: (id: string, label: string | null) => void;
    onDuplicate: (id: string) => void;
    onCopy: (id: string) => void;
    onPaste: (id: string, where: PasteWhere) => void;
    onRemove: (id: string) => void;
    /** O "▥": `anchor` é o sítio do botão no ecrã. */
    onAddStructure: (anchor: { x: number; y: number }) => void;
    onClose: () => void;
    /** Enquanto se arrasta, o Esc é do arrasto e não larga a seleção. */
    onDragging: (dragging: boolean) => void;
};

/**
 * A árvore da página. Arrasta-se pela pega (rato ou teclado): para cima e para
 * baixo muda a posição, para os lados o nível. Nada muda até largar, e o que o
 * `canDrop` recusa fica como estava.
 */
export function Navigator({
    nodes,
    selected,
    schema,
    limits,
    errors,
    onSelect,
    onMove,
    onToggle,
    onRename,
    onDuplicate,
    onCopy,
    onPaste,
    onRemove,
    onAddStructure,
    onClose,
    onDragging,
}: Props) {
    const [closed, setClosed] = useState<Set<string>>(() => new Set());
    const [activeId, setActiveId] = useState<string | null>(null);
    const [overId, setOverId] = useState<string | null>(null);
    const [shift, setShift] = useState(0);
    const [renaming, setRenaming] = useState<string | null>(null);
    const list = useRef<HTMLUListElement>(null);

    // O caminho até ao nó selecionado abre-se sozinho, só quando a seleção muda
    // (depois, o dono pode voltar a fechar).
    const [seenSelected, setSeenSelected] = useState<string | null>(null);

    if (seenSelected !== selected) {
        setSeenSelected(selected);

        if (selected) {
            const path = nodePath(nodes, selected).slice(0, -1);

            if (path.some((node) => closed.has(node.id))) {
                const next = new Set(closed);
                path.forEach((node) => next.delete(node.id));
                setClosed(next);
            }
        }
    }

    useEffect(() => {
        list.current
            ?.querySelector('[aria-current="true"]')
            ?.scrollIntoView({ block: 'nearest' });
    }, [selected]);

    const sensors = useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 4 } }),
        useSensor(KeyboardSensor, { coordinateGetter: coordinates }),
    );

    const rows = useMemo(
        () => flatten(nodes, closed, activeId),
        [nodes, closed, activeId],
    );
    const ids = useMemo(() => rows.map((row) => row.node.id), [rows]);

    const projection = useMemo(
        () =>
            activeId && overId
                ? project(nodes, rows, closed, activeId, overId, shift)
                : null,
        [nodes, rows, closed, activeId, overId, shift],
    );

    const refusal = useMemo(
        () =>
            activeId && projection
                ? canDrop(nodes, activeId, projection.target, schema, limits)
                : null,
        [nodes, activeId, projection, schema, limits],
    );

    // Um erro lá dentro também marca os contentores à volta.
    const failing = useMemo(() => {
        const marked = new Set<string>();

        for (const id of erroredNodeIds(errors)) {
            nodePath(nodes, id).forEach((node) => marked.add(node.id));
        }

        return marked;
    }, [errors, nodes]);

    const end = () => {
        setActiveId(null);
        setOverId(null);
        setShift(0);
        // O Esc que cancela ainda vai a caminho do atalho do editor.
        window.setTimeout(() => onDragging(false));
    };

    const onDragStart = ({ active }: DragStartEvent) => {
        setActiveId(String(active.id));
        setOverId(String(active.id));
        setShift(0);
        onDragging(true);
    };

    const onDragMove = ({ delta }: DragMoveEvent) =>
        setShift(Math.trunc(delta.x / LEVEL_STEP));

    const onDragOver = ({ over }: DragOverEvent) =>
        setOverId(over ? String(over.id) : null);

    const onDragEnd = ({ active }: DragEndEvent) => {
        if (projection) {
            if (refusal) {
                toast.error(refusal);
            } else {
                onMove(String(active.id), projection.target);
            }
        }

        end();
    };

    const nameOf = (id: unknown) => {
        const found = findNode(nodes, String(id));

        return found
            ? `“${nodeLabel(found.node, schema, found.parent)}”`
            : 'o elemento';
    };

    const announcements: Announcements = {
        onDragStart: ({ active }) => `Pegaste em ${nameOf(active.id)}.`,
        onDragOver: ({ active, over }) =>
            over
                ? `${nameOf(active.id)} está sobre ${nameOf(over.id)}.`
                : `${nameOf(active.id)} já não está sobre nenhum elemento.`,
        onDragEnd: ({ active }) => `${nameOf(active.id)} largado.`,
        onDragCancel: ({ active }) =>
            `Cancelado. ${nameOf(active.id)} voltou ao sítio.`,
    };

    const toggleOpen = (id: string) =>
        setClosed((current) => {
            const next = new Set(current);

            if (!next.delete(id)) {
                next.add(id);
            }

            return next;
        });

    const activeRow = activeId
        ? rows.find((row) => row.node.id === activeId)
        : null;

    return (
        <div data-navigator className="flex h-full min-h-0 flex-col">
            <div className="flex shrink-0 items-center gap-2 px-3 pt-3 pb-1">
                <h2 className="flex-1 text-sm font-medium">Navegador</h2>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="hidden size-7 lg:inline-flex"
                    aria-label="Fechar o Navegador"
                    onClick={onClose}
                >
                    <X />
                </Button>
            </div>
            <div className="min-h-0 flex-1 overflow-x-hidden overflow-y-auto px-2 pb-3">
                {nodes.length === 0 && (
                    <p className="px-1 py-8 text-center text-sm text-muted-foreground">
                        A página está vazia. Junta um contentor em Elementos.
                    </p>
                )}
                <DndContext
                    sensors={sensors}
                    collisionDetection={closestCenter}
                    accessibility={{
                        announcements,
                        screenReaderInstructions: {
                            draggable:
                                'Para pegar, carrega em espaço ou Enter. As setas para cima e para baixo mudam a posição, e para os lados o nível. Espaço ou Enter larga, Escape cancela.',
                        },
                    }}
                    onDragStart={onDragStart}
                    onDragMove={onDragMove}
                    onDragOver={onDragOver}
                    onDragEnd={onDragEnd}
                    onDragCancel={end}
                >
                    <SortableContext
                        items={ids}
                        strategy={verticalListSortingStrategy}
                    >
                        <ul ref={list} className="grid gap-px">
                            {rows.map((row) => {
                                const isActive = row.node.id === activeId;

                                return (
                                    <TreeRow
                                        key={row.node.id}
                                        row={row}
                                        depth={
                                            isActive && projection
                                                ? projection.depth
                                                : row.depth
                                        }
                                        name={nodeLabel(
                                            row.node,
                                            schema,
                                            row.parent,
                                        )}
                                        selected={row.node.id === selected}
                                        open={!closed.has(row.node.id)}
                                        schema={schema}
                                        failing={failing.has(row.node.id)}
                                        refused={isActive ? refusal : null}
                                        renaming={renaming === row.node.id}
                                        onRenaming={(on) =>
                                            setRenaming(on ? row.node.id : null)
                                        }
                                        onToggleOpen={() =>
                                            toggleOpen(row.node.id)
                                        }
                                        onSelect={() => onSelect(row.node.id)}
                                        onToggle={() => onToggle(row.node.id)}
                                        onRename={(label) =>
                                            onRename(row.node.id, label)
                                        }
                                        onDuplicate={() =>
                                            onDuplicate(row.node.id)
                                        }
                                        onCopy={() => onCopy(row.node.id)}
                                        onPaste={(where) =>
                                            onPaste(row.node.id, where)
                                        }
                                        onRemove={() => onRemove(row.node.id)}
                                    />
                                );
                            })}
                        </ul>
                    </SortableContext>
                    <DragOverlay dropAnimation={null}>
                        {activeRow && (
                            <div
                                className={cn(
                                    'max-w-64 rounded-md border bg-card px-2 py-1.5 text-sm shadow-lg',
                                    refusal
                                        ? 'border-destructive'
                                        : 'border-border',
                                )}
                            >
                                <span className="flex items-center gap-1.5">
                                    <BlockIcon
                                        type={activeRow.node.type}
                                        className="size-3.5 shrink-0 text-muted-foreground"
                                    />
                                    <span className="truncate">
                                        {nodeLabel(
                                            activeRow.node,
                                            schema,
                                            activeRow.parent,
                                        )}
                                    </span>
                                </span>
                                {refusal && (
                                    <span className="mt-1 block text-xs text-destructive">
                                        {refusal}
                                    </span>
                                )}
                            </div>
                        )}
                    </DragOverlay>
                </DndContext>
            </div>
            <div className="shrink-0 border-t border-border/60 p-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="w-full"
                    onClick={(event) => {
                        const box = event.currentTarget.getBoundingClientRect();

                        onAddStructure({
                            x: box.left + box.width / 2,
                            y: box.top,
                        });
                    }}
                >
                    <Columns3 />
                    Escolher estrutura
                </Button>
            </div>
        </div>
    );
}

type RowProps = {
    row: Row;
    depth: number;
    name: string;
    selected: boolean;
    open: boolean;
    schema: SchemaByType;
    failing: boolean;
    refused: string | null;
    renaming: boolean;
    onRenaming: (on: boolean) => void;
    onToggleOpen: () => void;
    onSelect: () => void;
    onToggle: () => void;
    onRename: (label: string | null) => void;
    onDuplicate: () => void;
    onCopy: () => void;
    onPaste: (where: PasteWhere) => void;
    onRemove: () => void;
};

function TreeRow({
    row,
    depth,
    name,
    selected,
    open,
    schema,
    failing,
    refused,
    renaming,
    onRenaming,
    onToggleOpen,
    onSelect,
    onToggle,
    onRename,
    onDuplicate,
    onCopy,
    onPaste,
    onRemove,
}: RowProps) {
    const { node } = row;
    const {
        attributes,
        listeners,
        setNodeRef,
        setActivatorNodeRef,
        transform,
        transition,
        isDragging,
    } = useSortable({ id: node.id });
    const isGroup = isEditorContainer(node);
    // Fechado, o contentor diz o que leva lá dentro.
    const peek =
        isEditorContainer(node) && !open && node.children[0]
            ? nodeLabel(node.children[0], schema, node)
            : null;

    return (
        <li
            ref={setNodeRef}
            style={{
                transform: CSS.Translate.toString(transform),
                transition,
                paddingLeft: depth * INDENT,
            }}
            title={refused ?? undefined}
        >
            <div
                className={cn(
                    'group flex items-center gap-0.5 rounded-md border border-transparent pr-0.5 transition-colors hover:bg-muted/60',
                    selected && 'bg-accent text-accent-foreground',
                    failing && 'border-destructive/60',
                    isDragging &&
                        'border-dashed border-ring bg-muted/60 opacity-70',
                    isDragging && refused && 'border-destructive',
                )}
            >
                <button
                    ref={setActivatorNodeRef}
                    type="button"
                    aria-label={`Mover “${name}”`}
                    className="flex h-7 w-4 shrink-0 cursor-grab touch-none items-center justify-center rounded text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none active:cursor-grabbing"
                    {...(attributes as HTMLAttributes<HTMLButtonElement>)}
                    {...listeners}
                >
                    <GripVertical aria-hidden className="size-3.5" />
                </button>
                {isGroup ? (
                    <button
                        type="button"
                        aria-label={`${open ? 'Fechar' : 'Abrir'} “${name}”`}
                        aria-expanded={open}
                        onClick={onToggleOpen}
                        className="flex size-5 shrink-0 items-center justify-center rounded text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        {open ? (
                            <ChevronDown aria-hidden className="size-3.5" />
                        ) : (
                            <ChevronRight aria-hidden className="size-3.5" />
                        )}
                    </button>
                ) : (
                    <span aria-hidden className="w-5 shrink-0" />
                )}
                {renaming ? (
                    <RenameInput
                        name={node.label ?? ''}
                        placeholder={name}
                        onDone={(label) => {
                            onRenaming(false);

                            if (label !== undefined && label !== node.label) {
                                onRename(label);
                            }
                        }}
                    />
                ) : (
                    <button
                        type="button"
                        aria-current={selected ? 'true' : undefined}
                        onClick={onSelect}
                        onDoubleClick={() => onRenaming(true)}
                        className={cn(
                            'flex min-w-0 flex-1 items-center gap-1.5 rounded py-1 text-left text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                            !node.visible && 'text-muted-foreground',
                        )}
                    >
                        {failing && (
                            <span className="size-2 shrink-0 rounded-full bg-destructive">
                                <span className="sr-only">Tem erros</span>
                            </span>
                        )}
                        <BlockIcon
                            type={node.type}
                            className="size-3.5 shrink-0 text-muted-foreground"
                        />
                        <span className="truncate">
                            {name}
                            {peek && (
                                <span className="text-muted-foreground">
                                    {' '}
                                    ({peek})
                                </span>
                            )}
                        </span>
                    </button>
                )}
                <span
                    className={cn(
                        'flex shrink-0 items-center',
                        !selected &&
                            node.visible &&
                            'lg:opacity-0 lg:group-focus-within:opacity-100 lg:group-hover:opacity-100',
                    )}
                >
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-6 text-muted-foreground"
                        aria-label={
                            node.visible
                                ? `Esconder “${name}”`
                                : `Mostrar “${name}”`
                        }
                        aria-pressed={!node.visible}
                        onClick={onToggle}
                    >
                        {node.visible ? <Eye /> : <EyeOff />}
                    </Button>
                    <RowMenu
                        name={name}
                        node={node}
                        schema={schema}
                        onDuplicate={onDuplicate}
                        onCopy={onCopy}
                        onPaste={onPaste}
                        onRemove={onRemove}
                    />
                </span>
            </div>
        </li>
    );
}

function RowMenu({
    name,
    node,
    schema,
    onDuplicate,
    onCopy,
    onPaste,
    onRemove,
}: {
    name: string;
    node: EditorNode;
    schema: SchemaByType;
    onDuplicate: () => void;
    onCopy: () => void;
    onPaste: (where: PasteWhere) => void;
    onRemove: () => void;
}) {
    const flags = subtreeFlags(node, schema);

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="size-6 text-muted-foreground"
                    aria-label={`Mais ações de “${name}”`}
                >
                    <MoreHorizontal />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                <DropdownMenuItem
                    disabled={flags.unique}
                    onSelect={onDuplicate}
                >
                    <CopyPlus />
                    Duplicar
                </DropdownMenuItem>
                <DropdownMenuItem onSelect={onCopy}>
                    <Copy />
                    Copiar
                </DropdownMenuItem>
                <DropdownMenuItem
                    disabled={!isEditorContainer(node)}
                    onSelect={() => onPaste('inside')}
                >
                    <ClipboardPaste />
                    Colar dentro
                </DropdownMenuItem>
                <DropdownMenuItem onSelect={() => onPaste('after')}>
                    <ClipboardPaste />
                    Colar depois
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem
                    variant="destructive"
                    disabled={flags.required}
                    onSelect={onRemove}
                >
                    <Trash2 />
                    Apagar
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/** O nome do nó; `undefined` = cancelado, `null` = volta ao nome do tipo. */
function RenameInput({
    name,
    placeholder,
    onDone,
}: {
    name: string;
    placeholder: string;
    onDone: (label: string | null | undefined) => void;
}) {
    const [value, setValue] = useState(name);
    const finished = useRef(false);

    const finish = (label: string | null | undefined) => {
        if (!finished.current) {
            finished.current = true;
            onDone(label);
        }
    };

    return (
        <Input
            autoFocus
            value={value}
            maxLength={60}
            placeholder={placeholder}
            aria-label="Nome"
            className="h-6 min-w-0 flex-1 px-1 text-sm"
            onFocus={(event) => event.target.select()}
            onChange={(event) => setValue(event.target.value)}
            onBlur={() => finish(value.trim() || null)}
            onKeyDown={(event) => {
                if (event.key === 'Enter') {
                    finish(value.trim() || null);
                }

                if (event.key === 'Escape') {
                    event.stopPropagation();
                    finish(undefined);
                }
            }}
        />
    );
}
