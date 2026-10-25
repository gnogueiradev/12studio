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
import { Copy, Eye, EyeOff, GripVertical, Lock, Trash2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import type { BlockSchema, EditorBlock } from '@/types/page-builder';
import { blockSummary, missingTranslations } from './blocks';

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
};

/**
 * A lista de blocos da página. Reordena-se pela pega ⠿, com o rato ou com o
 * teclado (espaço para pegar, setas para mover, espaço para largar). Os
 * blocos obrigatórios do template movem-se mas não se apagam.
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
}: Props) {
    const sensors = useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 4 } }),
        useSensor(KeyboardSensor, {
            coordinateGetter: sortableKeyboardCoordinates,
        }),
    );

    const onDragEnd = ({ active, over }: DragEndEvent) => {
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
                <ol className="grid grid-cols-1 gap-1.5">
                    {blocks.map((block, index) => (
                        <SortableBlock
                            key={block.id}
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
    schema,
    selected,
    invalid,
    missing,
    summary,
    onSelect,
    onToggle,
    onDuplicate,
    onRemove,
}: {
    block: EditorBlock;
    schema: BlockSchema | undefined;
    selected: boolean;
    invalid: boolean;
    missing: boolean;
    summary: string;
    onSelect: () => void;
    onToggle: () => void;
    onDuplicate: () => void;
    onRemove: () => void;
}) {
    const {
        attributes,
        listeners,
        setNodeRef,
        transform,
        transition,
        isDragging,
    } = useSortable({ id: block.id });
    const label = schema?.label ?? block.type;
    const required = schema?.required ?? false;

    return (
        <li
            ref={setNodeRef}
            style={{
                transform: CSS.Transform.toString(transform),
                transition,
            }}
            className={cn(
                'group flex min-w-0 items-center gap-1 rounded-lg border bg-card pr-1 transition-shadow',
                selected
                    ? 'border-foreground/60 ring-1 ring-foreground/20'
                    : 'border-border/60',
                isDragging && 'z-10 shadow-lg',
                !block.visible && 'opacity-60',
            )}
        >
            <button
                type="button"
                className="cursor-grab touch-none self-stretch rounded-l-lg px-1.5 text-muted-foreground hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
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
                className="min-w-0 flex-1 py-2 text-left focus-visible:outline-none"
            >
                <span className="flex items-center gap-1.5 text-sm font-medium">
                    {label}
                    {required && (
                        <Lock
                            aria-label="Obrigatório"
                            className="size-3 text-muted-foreground"
                        />
                    )}
                    {invalid && (
                        <span
                            className="size-1.5 rounded-full bg-destructive"
                            aria-label="Tem erros"
                        />
                    )}
                    {missing && !invalid && (
                        <span
                            className="size-1.5 rounded-full bg-warning"
                            aria-label="Falta tradução"
                        />
                    )}
                </span>
                {summary && (
                    <span className="block truncate text-xs text-muted-foreground">
                        {summary}
                    </span>
                )}
            </button>
            <Action
                label={block.visible ? 'Esconder' : 'Mostrar'}
                onClick={onToggle}
            >
                {block.visible ? <Eye /> : <EyeOff />}
            </Action>
            {!schema?.unique && (
                <Action label="Duplicar" onClick={onDuplicate}>
                    <Copy />
                </Action>
            )}
            {!required && (
                <Action label="Apagar" onClick={onRemove}>
                    <Trash2 />
                </Action>
            )}
        </li>
    );
}

function Action({
    label,
    onClick,
    children,
}: {
    label: string;
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
            <TooltipContent>{label}</TooltipContent>
        </Tooltip>
    );
}
