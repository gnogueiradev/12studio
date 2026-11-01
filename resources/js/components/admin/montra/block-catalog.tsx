import { Search } from 'lucide-react';
import { useRef, useState } from 'react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { walkNodes } from '@/lib/page-tree';
import type { BlockSchema, EditorNode } from '@/types/page-builder';
import { BlockIcon, nodeLabel } from './blocks';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    schema: BlockSchema[];
    nodes: EditorNode[];
    /** Onde entra o bloco novo; null = a seguir ao selecionado (ou no fim). */
    at: number | null;
    onAdd: (schema: BlockSchema) => void;
};

/** "+ Adicionar bloco": os blocos que esta página pode ter. */
export function BlockCatalog({
    open,
    onOpenChange,
    schema,
    nodes,
    at,
    onAdd,
}: Props) {
    const neighbour =
        at !== null && at < nodes.length
            ? nodeLabel(
                  nodes[at],
                  Object.fromEntries(
                      schema.map((block) => [block.type, block]),
                  ),
              )
            : null;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="flex max-h-[85vh] flex-col gap-4 sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Adicionar bloco</DialogTitle>
                    <DialogDescription>
                        {at === null
                            ? 'Entra a seguir ao bloco selecionado (ou no fim).'
                            : neighbour
                              ? `Entra antes de “${neighbour}”.`
                              : 'Entra no fim da página.'}{' '}
                        Depois podes arrastá-lo para onde quiseres.
                    </DialogDescription>
                </DialogHeader>
                {/* Só existe com o diálogo aberto: a pesquisa começa vazia. */}
                <CatalogBody
                    schema={schema}
                    nodes={nodes}
                    onPick={(block) => {
                        onAdd(block);
                        onOpenChange(false);
                    }}
                />
            </DialogContent>
        </Dialog>
    );
}

function CatalogBody({
    schema,
    nodes,
    onPick,
}: {
    schema: BlockSchema[];
    nodes: EditorNode[];
    onPick: (schema: BlockSchema) => void;
}) {
    const [query, setQuery] = useState('');
    const list = useRef<HTMLUListElement>(null);
    const used = new Set(walkNodes(nodes).map((node) => node.type));
    const needle = normalize(query.trim());
    const matches = needle
        ? schema.filter((block) =>
              normalize(`${block.label} ${block.description}`).includes(needle),
          )
        : schema;
    const isTaken = (block: BlockSchema) =>
        block.unique && used.has(block.type);

    return (
        <>
            <div className="relative">
                <Search
                    aria-hidden
                    className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    type="search"
                    autoFocus
                    value={query}
                    onChange={(event) => setQuery(event.target.value)}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter') {
                            event.preventDefault();

                            // Sem pesquisa, o Enter não escolhe nada.
                            if (query.trim() === '') {
                                return;
                            }

                            const first = matches.find(
                                (block) => !isTaken(block),
                            );

                            if (first) {
                                onPick(first);
                            }
                        }

                        if (event.key === 'ArrowDown') {
                            event.preventDefault();
                            list.current
                                ?.querySelector<HTMLButtonElement>(
                                    'button:not(:disabled)',
                                )
                                ?.focus();
                        }
                    }}
                    placeholder="Procurar blocos…"
                    aria-label="Procurar blocos"
                    className="pl-9"
                />
            </div>
            {matches.length === 0 ? (
                <p className="py-8 text-center text-sm text-muted-foreground">
                    Nenhum bloco com “{query.trim()}”.
                </p>
            ) : (
                <ul
                    ref={list}
                    className="-mx-1 grid min-h-0 gap-2 overflow-y-auto px-1 pb-1 sm:grid-cols-2"
                >
                    {matches.map((block) => {
                        const taken = isTaken(block);

                        return (
                            <li key={block.type}>
                                <button
                                    type="button"
                                    disabled={taken}
                                    onClick={() => onPick(block)}
                                    className="flex h-full w-full items-start gap-3 rounded-lg border border-border/60 p-3 text-left transition-colors hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    <span className="flex size-9 shrink-0 items-center justify-center rounded-md bg-muted">
                                        <BlockIcon
                                            type={block.type}
                                            className="size-4"
                                        />
                                    </span>
                                    <span className="grid gap-0.5">
                                        <span className="text-sm font-medium">
                                            {block.label}
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            {taken
                                                ? 'Já está na página (só pode haver um).'
                                                : block.description}
                                        </span>
                                    </span>
                                </button>
                            </li>
                        );
                    })}
                </ul>
            )}
            {needle && matches.length > 0 && (
                <p className="text-xs text-muted-foreground">
                    Enter adiciona o primeiro.
                </p>
            )}
        </>
    );
}

/** Sem maiúsculas nem acentos: "botao" encontra "Botão". */
function normalize(text: string): string {
    return text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}
