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
    SortableContext,
    sortableKeyboardCoordinates,
    useSortable,
    verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import {
    Copy,
    Eye,
    EyeOff,
    GripVertical,
    Lock,
    Plus,
    Trash2,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import type { BlockSchema, EditorBlock } from '@/types/page-builder';
import { BlockIcon, blockSummary, missingTranslations } from './blocks';
import { MOD } from './shortcuts';

type Props = {
    blocks: EditorBlock[];
    schema: Record<string, BlockSchema>;
    selected: string | null;
    locales: string[];
    defaultLocale: string;
    errorBlocks: Set<number>;
    onSelect: (id: string) => void;
    onMove: (from: number, to: number) => void;
    onToggle: (id: string) => void;
    onDuplicate: (id: string) => void;
    onRemove: (id: string) => void;
    /** Abre o catálogo para pôr um bloco novo nesta posição. */
    onInsert: (at: number) => void;
};

/**
 * A lista de blocos da página. Reordena-se pela pega ⠿, com o rato ou com o
 * teclado (espaço para pegar, setas para mover, espaço para largar). Os
 * blocos obrigatórios do template movem-se mas não se apagam. Entre blocos
 * há uma linha "+" (ao passar o rato ou com o Tab) para meter um ali.
 */
export function BlockList({
    blocks,
    schema,
    selected,
    locales,
    defaultLocale,
    errorBlocks,
    onSelect,
    onMove,
    onToggle,
    onDuplicate,
    onRemove,
    onInsert,
}: Props) {
    const [dragging, setDragging] = useState(false);
    const sensors = useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 4 } }),
        useSensor(KeyboardSensor, {
            coordinateGetter: sortableKeyboardCoordinates,
        }),
    );

    const onDragEnd = ({ active, over }: DragEndEvent) => {
        setDragging(false);

        if (!over || active.id === over.id) {
            return;
        }

        const from = blocks.findIndex((block) => block.id === active.id);
        const to = blocks.findIndex((block) => block.id === over.id);

        if (from >= 0 && to >= 0) {
            onMove(from, to);
        }
    };

    if (blocks.length === 0) {
        return (
            <p className="rounded-md border border-dashed border-border p-4 text-center text-sm text-muted-foreground">
                A página ainda não tem blocos. Começa por “Adicionar bloco”.
            </p>
        );
    }

    return (
        <DndContext
            sensors={sensors}
            collisionDetection={closestCenter}
            onDragStart={() => setDragging(true)}
            onDragCancel={() => setDragging(false)}
            onDragEnd={onDragEnd}
            accessibility={{
                screenReaderInstructions: {
                    draggable:
                        'Para mover um bloco, carrega em espaço, usa as setas e carrega outra vez em espaço para largar. Escape cancela.',
                },
            }}
        >
            <SortableContext
                items={blocks.map((block) => block.id)}
                strategy={verticalListSortingStrategy}
            >
                <ol data-block-list className="grid grid-cols-1">
                    <li className={cn(dragging && 'invisible')}>
                        <InsertSlot at={0} onInsert={onInsert} />
                    </li>
                    {blocks.map((block, index) => (
                        <SortableBlock
                            key={block.id}
                            index={index}
                            dragging={dragging}
                            onInsert={onInsert}
                            block={block}
                            schema={schema[block.type]}
                            selected={selected === block.id}
                            invalid={errorBlocks.has(index)}
                            missing={
                                missingTranslations(
                                    block,
                                    schema[block.type],
                                    locales,
                                    defaultLocale,
                                ).length > 0
                            }
                            summary={blockSummary(block, defaultLocale)}
                            onSelect={() => onSelect(block.id)}
                            onToggle={() => onToggle(block.id)}
                            onDuplicate={() => onDuplicate(block.id)}
                            onRemove={() => onRemove(block.id)}
                        />
                    ))}
                </ol>
            </SortableContext>
        </DndContext>
    );
}

function SortableBlock({
    block,
    index,
    schema,
    selected,
    invalid,
    missing,
    summary,
    dragging,
    onSelect,
    onToggle,
    onDuplicate,
    onRemove,
    onInsert,
}: {
    block: EditorBlock;
    index: number;
    schema: BlockSchema | undefined;
    selected: boolean;
    invalid: boolean;
    missing: boolean;
    summary: string;
    dragging: boolean;
    onSelect: () => void;
    onToggle: () => void;
    onDuplicate: () => void;
    onRemove: () => void;
    onInsert: (at: number) => void;
}) {
    const {
        attributes,
        listeners,
        setNodeRef,
        transform,
        transition,
        isDragging,
    } = useSortable({ id: block.id });
    const row = useRef<HTMLLIElement | null>(null);
    const label = schema?.label ?? block.type;
    const required = schema?.required ?? false;

    // Escolhido na pré-visualização: a lista vai até ele.
    useEffect(() => {
        if (selected) {
            row.current?.scrollIntoView({ block: 'nearest' });
        }
    }, [selected]);

    return (
        <li
            ref={(node) => {
                setNodeRef(node);
                row.current = node;
            }}
            style={{
                transform: CSS.Transform.toString(transform),
                transition,
            }}
            className={cn('relative', isDragging && 'z-10')}
        >
            <div
                className={cn(
                    'group/row relative flex min-w-0 items-center gap-1 rounded-lg border bg-card pr-1 transition-[border-color,box-shadow]',
                    selected
                        ? 'border-ring ring-1 ring-ring/40'
                        : 'border-border/60 hover:border-border',
                    isDragging && 'shadow-lg',
                )}
            >
                <button
                    type="button"
                    className="cursor-grab touch-none self-stretch rounded-l-lg px-1 text-muted-foreground hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    aria-label={`Mover o bloco ${label}`}
                    {...attributes}
                    {...listeners}
                >
                    <GripVertical className="size-4" />
                </button>
                <button
                    type="button"
                    onClick={onSelect}
                    aria-pressed={selected}
                    className={cn(
                        'flex min-w-0 flex-1 items-center gap-2.5 rounded-md py-2 text-left focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                        !block.visible && 'opacity-60',
                    )}
                >
                    <span
                        className={cn(
                            'flex size-8 shrink-0 items-center justify-center rounded-md',
                            selected
                                ? 'bg-primary text-primary-foreground'
                                : 'bg-muted text-muted-foreground',
                        )}
                    >
                        <BlockIcon type={block.type} className="size-4" />
                    </span>
                    <span className="min-w-0 flex-1">
                        <span className="flex items-center gap-1.5 text-sm font-medium">
                            <span className="truncate">{label}</span>
                            {required && (
                                <Lock
                                    aria-label="Obrigatório"
                                    className="size-3 shrink-0 text-muted-foreground"
                                />
                            )}
                            {!block.visible && (
                                <EyeOff
                                    aria-label="Escondido"
                                    className="size-3 shrink-0 text-muted-foreground"
                                />
                            )}
                            {invalid && (
                                <span
                                    className="size-1.5 shrink-0 rounded-full bg-destructive"
                                    aria-label="Tem erros"
                                />
                            )}
                            {missing && !invalid && (
                                <span
                                    className="size-1.5 shrink-0 rounded-full bg-warning"
                                    aria-label="Falta tradução"
                                />
                            )}
                        </span>
                        {summary && (
                            <span className="block truncate text-xs text-muted-foreground">
                                {summary}
                            </span>
                        )}
                    </span>
                </button>
                {/* Com rato, as ações aparecem ao passar por cima (ou com o
                    foco) por cima do fim do texto, para o nome não ficar
                    cortado; em ecrãs de toque ficam sempre à vista. */}
                <div
                    className={cn(
                        'flex shrink-0 items-center transition-opacity',
                        !selected &&
                            'pointer-fine:pointer-events-none pointer-fine:absolute pointer-fine:inset-y-1 pointer-fine:right-1 pointer-fine:rounded-md pointer-fine:bg-card pointer-fine:pl-1 pointer-fine:opacity-0 pointer-fine:shadow-[-12px_0_12px_-4px_var(--card)] pointer-fine:group-focus-within/row:pointer-events-auto pointer-fine:group-focus-within/row:opacity-100 pointer-fine:group-hover/row:pointer-events-auto pointer-fine:group-hover/row:opacity-100',
                    )}
                >
                    <Action
                        label={block.visible ? 'Esconder' : 'Mostrar'}
                        onClick={onToggle}
                    >
                        {block.visible ? <Eye /> : <EyeOff />}
                    </Action>
                    {!schema?.unique && (
                        <Action
                            label="Duplicar"
                            hint={`${MOD} D`}
                            onClick={onDuplicate}
                        >
                            <Copy />
                        </Action>
                    )}
                    {!required && (
                        <Action label="Apagar" hint="Delete" onClick={onRemove}>
                            <Trash2 />
                        </Action>
                    )}
                </div>
            </div>
            <div className={cn(dragging && 'invisible')}>
                <InsertSlot at={index + 1} onInsert={onInsert} />
            </div>
        </li>
    );
}

/** A linha fininha entre blocos: um "+" que mete um bloco ali. */
function InsertSlot({
    at,
    onInsert,
}: {
    at: number;
    onInsert: (at: number) => void;
}) {
    return (
        <button
            type="button"
            onClick={() => onInsert(at)}
            aria-label={`Adicionar um bloco na posição ${at + 1}`}
            className="group/slot flex h-3 w-full items-center px-2 focus-visible:outline-none"
        >
            <span className="flex w-full items-center opacity-0 transition-opacity group-hover/slot:opacity-100 group-focus-visible/slot:opacity-100">
                <span className="h-px flex-1 bg-ring" />
                <span className="mx-1 flex size-4 items-center justify-center rounded-full bg-ring text-background">
                    <Plus aria-hidden className="size-3" strokeWidth={3} />
                </span>
                <span className="h-px flex-1 bg-ring" />
            </span>
        </button>
    );
}

function Action({
    label,
    hint,
    onClick,
    children,
}: {
    label: string;
    hint?: string;
    onClick: () => void;
    children: React.ReactNode;
}) {
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="size-7"
                    aria-label={label}
                    onClick={onClick}
                >
                    {children}
                </Button>
            </TooltipTrigger>
            <TooltipContent>
                {label}
                {hint && <span className="ml-1.5 opacity-70">{hint}</span>}
            </TooltipContent>
        </Tooltip>
    );
}
