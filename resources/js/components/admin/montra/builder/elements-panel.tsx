import { Search } from 'lucide-react';
import { useState } from 'react';
import { Input } from '@/components/ui/input';
import { walkNodes } from '@/lib/page-tree';
import { cn } from '@/lib/utils';
import type { BlockSchema, EditorNode } from '@/types/page-builder';
import { BlockIcon } from '../blocks';

/** O tipo que vai no `dataTransfer` ao arrastar da grelha. */
export const ELEMENT_DRAG_TYPE = 'application/x-studio-element';

/** Não são blocos do servidor: o editor faz o contentor (ver `newSubject`). */
const STRUCTURES = [
    {
        type: 'container',
        label: 'Contentor',
        description: 'Uma caixa para meter elementos',
    },
    {
        type: 'columns',
        label: 'Colunas',
        description: 'Um contentor com duas colunas lado a lado',
    },
];

type Tile = {
    type: string;
    label: string;
    description: string;
    taken: boolean;
};

type Props = {
    schema: BlockSchema[];
    nodes: EditorNode[];
    onInsert: (type: string) => void;
    onDragType: (type: string | null) => void;
};

/**
 * A grelha do painel esquerdo. O `schema` já só traz o que este modelo de
 * página aceita. Clicar insere; arrastar leva o tipo para a página.
 */
export function ElementsPanel({ schema, nodes, onInsert, onDragType }: Props) {
    const [query, setQuery] = useState('');
    const needle = normalize(query.trim());
    const used = new Set(walkNodes(nodes).map((node) => node.type));

    const tile = (block: BlockSchema): Tile => ({
        type: block.type,
        label: block.label,
        description: block.description,
        taken: block.unique && used.has(block.type),
    });
    const groups: { name: string; tiles: Tile[] }[] = [
        {
            name: 'Estrutura',
            tiles: [
                ...STRUCTURES.map((item) => ({ ...item, taken: false })),
                ...schema.filter((b) => b.type === 'spacer').map(tile),
            ],
        },
        {
            name: 'Básicos',
            tiles: schema.filter((block) => !block.hasShell).map(tile),
        },
        {
            name: 'Da loja',
            tiles: schema
                .filter((block) => block.hasShell && block.type !== 'spacer')
                .map(tile),
        },
    ]
        .map((group) => ({
            ...group,
            tiles: group.tiles.filter(
                (item) =>
                    !needle ||
                    normalize(`${item.label} ${item.description}`).includes(
                        needle,
                    ),
            ),
        }))
        .filter((group) => group.tiles.length > 0);

    return (
        <div className="flex min-h-0 flex-1 flex-col">
            <div className="shrink-0 px-3 pb-2">
                <div className="relative">
                    <Search
                        aria-hidden
                        className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <Input
                        type="search"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder="Procurar elemento"
                        aria-label="Procurar elemento"
                        className="pl-9"
                    />
                </div>
            </div>
            <div className="min-h-0 flex-1 overflow-x-hidden overflow-y-auto px-3 pb-3">
                {groups.length === 0 && (
                    <p className="py-8 text-center text-sm text-muted-foreground">
                        Nenhum elemento com “{query.trim()}”.
                    </p>
                )}
                {groups.map((group) => (
                    <section
                        key={group.name}
                        aria-label={group.name}
                        className="mt-2 first:mt-0"
                    >
                        <h3 className="mb-1.5 text-xs font-medium text-muted-foreground">
                            {group.name}
                        </h3>
                        <ul className="grid grid-cols-3 gap-1.5">
                            {group.tiles.map((item) => (
                                <li key={item.type}>
                                    <ElementTile
                                        tile={item}
                                        onInsert={onInsert}
                                        onDragType={onDragType}
                                    />
                                </li>
                            ))}
                        </ul>
                    </section>
                ))}
            </div>
        </div>
    );
}

/** Um `div`, porque no Firefox um `<button>` não se deixa arrastar. */
function ElementTile({
    tile,
    onInsert,
    onDragType,
}: {
    tile: Tile;
    onInsert: (type: string) => void;
    onDragType: (type: string | null) => void;
}) {
    return (
        <div
            role="button"
            tabIndex={tile.taken ? -1 : 0}
            aria-disabled={tile.taken}
            draggable={!tile.taken}
            title={
                tile.taken
                    ? 'Já está na página (só pode haver um).'
                    : tile.description
            }
            onClick={() => {
                if (!tile.taken) {
                    onInsert(tile.type);
                }
            }}
            onKeyDown={(event) => {
                if (
                    !tile.taken &&
                    (event.key === 'Enter' || event.key === ' ')
                ) {
                    event.preventDefault();
                    onInsert(tile.type);
                }
            }}
            onDragStart={(event) => {
                event.dataTransfer.setData(ELEMENT_DRAG_TYPE, tile.type);
                event.dataTransfer.effectAllowed = 'copy';
                onDragType(tile.type);
            }}
            onDragEnd={() => onDragType(null)}
            className={cn(
                'flex h-full min-h-16 flex-col items-center justify-center gap-1 rounded-md border border-border/60 bg-card p-1 text-center text-[11px] leading-tight transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                tile.taken
                    ? 'cursor-not-allowed opacity-50'
                    : 'cursor-grab hover:bg-muted active:cursor-grabbing',
            )}
        >
            <BlockIcon
                type={tile.type}
                className="size-4 shrink-0 text-muted-foreground"
            />
            <span className="w-full hyphens-none">{tile.label}</span>
        </div>
    );
}

/** Sem maiúsculas nem acentos: "botao" encontra "Botão". */
function normalize(text: string): string {
    return text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}
