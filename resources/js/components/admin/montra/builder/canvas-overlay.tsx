import { Columns3, CopyPlus, GripVertical, Plus, X } from 'lucide-react';
import { useEffect, useEffectEvent, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { cn } from '@/lib/utils';
import type { PreviewMessage } from '@/types/page-builder';
import {
    computeDropTarget,
    ELEMENT_DRAG_TYPE,
    NODE_DRAG_TYPE,
    ROOT_KEY,
} from './dnd-protocol';
import type { CanvasTree, DropLevel, DropResult } from './dnd-protocol';

type Box = { left: number; top: number; width: number; height: number };

type Boxes = {
    selected: Box | null;
    hovered: Box | null;
    /** A secção de raiz debaixo do rato, para o "+ ▥" entre contentores. */
    section: { box: Box; id: string } | null;
    /** Os contentores vazios; `id: ''` é o fim da página. */
    zones: { id: string; box: Box }[];
};

type Feedback = {
    line: DropResult['line'];
    /** O contentor vazio onde vai cair; `''` é a página vazia. */
    inside: string | null;
};

const NO_BOXES: Boxes = {
    selected: null,
    hovered: null,
    section: null,
    zones: [],
};

/** Em píxeis do ecrã: a escala é contrariada ao usar. */
const EDGE = 12;
const LABEL_HEIGHT = 26;
/** Sem `dragover` há este tempo, o arrasto já saiu daqui. */
const DRAG_WATCHDOG = 800;

const CANVAS_CSS = `
[data-block-id]{cursor:pointer}
[data-pb-empty]{display:block;width:100%;min-width:8rem;min-height:6rem}
[data-pb-empty=""]{min-height:8rem}
[data-pb-collapsed]{min-width:8rem}
[data-pb-collapsed]::before{content:attr(data-pb-label) " · vazio";display:block;padding:.5rem .75rem;font-size:.875rem;line-height:1.25rem;text-align:center;color:var(--muted-foreground);border:1px dashed var(--border);border-radius:.25rem}
`;

function send(message: PreviewMessage) {
    window.parent.postMessage(message, window.location.origin);
}

function hostOf(element: Element): Element {
    return element.querySelector(':scope > .pb-inner') ?? element;
}

function drawn(element: Element): boolean {
    return element.getClientRects().length > 0;
}

function parentNode(element: Element): Element | null {
    return element.parentElement?.closest('[data-node-id]') ?? null;
}

function nodeId(element: Element): string {
    return (element as HTMLElement).dataset.nodeId ?? '';
}

/** O contentor (ou a página, sem `element`) como o `computeDropTarget` o quer. */
function levelOf(element: Element | null, tree: CanvasTree): DropLevel {
    const id = element ? nodeId(element) : null;
    const host = element ? hostOf(element) : null;
    const nodes = host
        ? Array.from(host.children).filter((child) =>
              child.matches('[data-node-id]'),
          )
        : Array.from(document.querySelectorAll('[data-node-id]')).filter(
              (node) => !parentNode(node),
          );
    const order = tree.children[id ?? ROOT_KEY] ?? [];

    return {
        id,
        rect: element?.getBoundingClientRect() ?? null,
        inner: host?.getBoundingClientRect() ?? null,
        direction:
            host && getComputedStyle(host).flexDirection.startsWith('row')
                ? 'row'
                : 'column',
        count: Math.max(order.length, nodes.length),
        children: nodes.flatMap((node, position) => {
            if (!drawn(node)) {
                return [];
            }

            const at = order.indexOf(nodeId(node));

            return {
                id: nodeId(node),
                rect: node.getBoundingClientRect(),
                index: at >= 0 ? at : position,
            };
        }),
    };
}

function isOurDrag(event: DragEvent): boolean {
    const types = event.dataTransfer?.types ?? [];

    return types.includes(NODE_DRAG_TYPE) || types.includes(ELEMENT_DRAG_TYPE);
}

type Props = {
    selected: string | null;
    tree: CanvasTree;
    scale: number;
    /** O tipo que vem da grelha do editor, enquanto se arrasta. */
    dragType: string | null;
};

/**
 * O que a página tem a mais no editor: contornos, etiqueta, zonas de largar e a
 * linha de onde vai cair. Tudo numa camada por cima que não mexe na página, à
 * escala do iframe; o que tem de se ler ou agarrar contraria a escala.
 *
 * Só corre dentro do iframe do editor, depois de ele se dar a conhecer (ver
 * EditorPreview no store/page.tsx).
 */
export default function CanvasOverlay({
    selected,
    tree,
    scale,
    dragType,
}: Props) {
    const layer = useRef<HTMLDivElement>(null);
    const label = useRef<HTMLDivElement>(null);
    const moving = useRef<string | null>(null);
    const frame = useRef(0);
    const watchdog = useRef(0);
    const [host] = useState<Element>(
        () => document.querySelector('.store-theme') ?? document.body,
    );
    const [hovered, setHovered] = useState<string | null>(null);
    const [boxes, setBoxes] = useState<Boxes>(NO_BOXES);
    const [feedback, setFeedback] = useState<Feedback | null>(null);

    const inverse = 1 / scale;

    // ── Medir ─────────────────────────────────────────────────────────────
    const measure = useEffectEvent(() => {
        const origin = layer.current?.getBoundingClientRect();

        if (!origin) {
            return;
        }

        const boxOf = (element: Element): Box => {
            const rect = element.getBoundingClientRect();

            return {
                left: rect.left - origin.left,
                top: rect.top - origin.top,
                width: rect.width,
                height: rect.height,
            };
        };
        const find = (id: string | null) => {
            const element = id
                ? document.querySelector(`[data-node-id="${CSS.escape(id)}"]`)
                : null;

            return element && drawn(element) ? element : null;
        };

        // Um elemento que não desenha nada (o texto ainda por escrever) ganha
        // o nome, senão não se via nem se selecionava.
        document
            .querySelectorAll<HTMLElement>('[data-block-id]')
            .forEach((element) => {
                const empty = Array.from(element.children).every(
                    (child) => child.getBoundingClientRect().height < 1,
                );

                if (empty) {
                    element.dataset.pbLabel =
                        tree.labels[element.dataset.blockId ?? ''] ??
                        'Elemento';
                    element.dataset.pbCollapsed = '';
                } else if ('pbCollapsed' in element.dataset) {
                    delete element.dataset.pbCollapsed;
                }
            });

        const selectedElement = find(selected);
        const hoveredElement = find(hovered);
        let root = hoveredElement;

        while (root && parentNode(root)) {
            root = parentNode(root);
        }

        const next: Boxes = {
            selected: selectedElement ? boxOf(selectedElement) : null,
            hovered: hoveredElement ? boxOf(hoveredElement) : null,
            section: root ? { box: boxOf(root), id: nodeId(root) } : null,
            zones: Array.from(
                document.querySelectorAll<HTMLElement>('[data-pb-empty]'),
            )
                .filter(drawn)
                .map((element) => ({
                    id: element.dataset.pbEmpty ?? '',
                    box: boxOf(element),
                })),
        };

        setBoxes((current) =>
            JSON.stringify(current) === JSON.stringify(next) ? current : next,
        );
    });

    const schedule = useEffectEvent(() => {
        cancelAnimationFrame(frame.current);
        frame.current = requestAnimationFrame(() => measure());
    });

    useEffect(() => {
        const tick = () => schedule();
        const mutations = new MutationObserver((records) => {
            // O que a própria camada muda não conta, senão nunca mais parava.
            if (records.some((r) => !layer.current?.contains(r.target))) {
                tick();
            }
        });
        mutations.observe(document.body, {
            subtree: true,
            childList: true,
            characterData: true,
            attributes: true,
            attributeFilter: ['style', 'class'],
        });
        const sizes = new ResizeObserver(tick);
        sizes.observe(document.documentElement);
        sizes.observe(document.body);
        window.addEventListener('resize', tick);
        // As imagens não mudam o DOM quando chegam.
        document.addEventListener('load', tick, true);
        void document.fonts?.ready.then(tick);

        return () => {
            mutations.disconnect();
            sizes.disconnect();
            window.removeEventListener('resize', tick);
            document.removeEventListener('load', tick, true);
            cancelAnimationFrame(frame.current);
        };
    }, []);

    useEffect(() => {
        schedule();
    }, [selected, hovered, tree, scale]);

    // ── Rato ──────────────────────────────────────────────────────────────
    useEffect(() => {
        const onOver = (event: MouseEvent) => {
            const target = event.target as Element;

            // Em cima da etiqueta ou de um botão, fica o que estava.
            if (!layer.current?.contains(target)) {
                setHovered(
                    target
                        .closest?.('[data-node-id]')
                        ?.getAttribute('data-node-id') ?? null,
                );
            }
        };
        const onLeave = () => setHovered(null);

        document.addEventListener('mouseover', onOver);
        document.documentElement.addEventListener('mouseleave', onLeave);

        return () => {
            document.removeEventListener('mouseover', onOver);
            document.documentElement.removeEventListener('mouseleave', onLeave);
        };
    }, []);

    // ── Arrastar ──────────────────────────────────────────────────────────
    const clearDrag = useEffectEvent(() => {
        window.clearTimeout(watchdog.current);
        setFeedback(null);
    });

    // O editor avisa quando o arrasto da grelha acaba (largou, Esc, fora).
    const [seenDragType, setSeenDragType] = useState(dragType);

    if (seenDragType !== dragType) {
        setSeenDragType(dragType);

        if (dragType === null) {
            setFeedback(null);
        }
    }

    const resolve = useEffectEvent((event: DragEvent): DropResult | null => {
        const under = document
            .elementsFromPoint(event.clientX, event.clientY)
            .find((element) => !layer.current?.contains(element));
        const chain: Element[] = [];
        let element: Element | null = null;

        for (
            let at = under?.closest('[data-node-id]') ?? null;
            at;
            at = parentNode(at)
        ) {
            if (at.matches('[data-block-id]')) {
                element = at;
            } else {
                chain.unshift(at);
            }
        }

        return computeDropTarget({
            levels: [null, ...chain].map((level) => levelOf(level, tree)),
            element: element
                ? {
                      id: nodeId(element),
                      rect: element.getBoundingClientRect(),
                  }
                : null,
            pointer: { x: event.clientX, y: event.clientY },
            edge: EDGE * inverse,
            dragId: moving.current,
        });
    });

    const onDragStart = useEffectEvent((event: DragEvent) => {
        const handle = (event.target as Element).closest?.<HTMLElement>(
            '[data-pb-handle]',
        );

        // As imagens e os links da página não se arrastam aqui.
        if (!handle || !event.dataTransfer) {
            event.preventDefault();

            return;
        }

        moving.current = handle.dataset.pbHandle ?? null;
        event.dataTransfer.setData(NODE_DRAG_TYPE, moving.current ?? '');
        event.dataTransfer.effectAllowed = 'move';

        if (label.current) {
            event.dataTransfer.setDragImage(label.current, 12, 12);
        }
    });

    const onDragOver = useEffectEvent((event: DragEvent) => {
        if (!isOurDrag(event) || !event.dataTransfer) {
            return;
        }

        event.preventDefault();
        setHovered(null);

        window.clearTimeout(watchdog.current);
        watchdog.current = window.setTimeout(
            () => setFeedback(null),
            DRAG_WATCHDOG,
        );

        const result = resolve(event);
        const effect = moving.current ? 'move' : 'copy';
        event.dataTransfer.dropEffect = result ? effect : 'none';

        const origin = layer.current?.getBoundingClientRect();
        const next: Feedback | null =
            result && origin
                ? {
                      inside: result.inside
                          ? (result.target.parentId ?? ROOT_KEY)
                          : null,
                      line: result.line && {
                          ...result.line,
                          x: result.line.x - origin.left,
                          y: result.line.y - origin.top,
                      },
                  }
                : null;

        setFeedback((current) =>
            JSON.stringify(current) === JSON.stringify(next) ? current : next,
        );
    });

    const onDrop = useEffectEvent((event: DragEvent) => {
        if (!isOurDrag(event) || !event.dataTransfer) {
            return;
        }

        event.preventDefault();

        const result = resolve(event);
        const id = event.dataTransfer.getData(NODE_DRAG_TYPE) || moving.current;
        const type = event.dataTransfer.getData(ELEMENT_DRAG_TYPE) || dragType;

        if (result && id) {
            send({
                source: 'studio-preview',
                kind: 'move',
                id,
                target: result.target,
            });
        } else if (result && type) {
            send({
                source: 'studio-preview',
                kind: 'drop',
                type,
                target: result.target,
            });
        }

        moving.current = null;
        clearDrag();
    });

    const onDragEnd = useEffectEvent(() => {
        moving.current = null;
        clearDrag();
    });

    const onDragLeave = useEffectEvent((event: DragEvent) => {
        const { clientX: x, clientY: y } = event;

        if (
            event.relatedTarget === null &&
            (x <= 0 ||
                y <= 0 ||
                x >= window.innerWidth ||
                y >= window.innerHeight)
        ) {
            clearDrag();
        }
    });

    useEffect(() => {
        const start = (event: DragEvent) => onDragStart(event);
        const over = (event: DragEvent) => onDragOver(event);
        const drop = (event: DragEvent) => onDrop(event);
        const end = () => onDragEnd();
        const leave = (event: DragEvent) => onDragLeave(event);

        document.addEventListener('dragstart', start);
        document.addEventListener('dragover', over);
        document.addEventListener('drop', drop);
        document.addEventListener('dragend', end);
        document.addEventListener('dragleave', leave);

        return () => {
            document.removeEventListener('dragstart', start);
            document.removeEventListener('dragover', over);
            document.removeEventListener('drop', drop);
            document.removeEventListener('dragend', end);
            document.removeEventListener('dragleave', leave);
            window.clearTimeout(watchdog.current);
        };
    }, []);

    // ── Teclado ───────────────────────────────────────────────────────────
    // O foco dentro do iframe não chega à janela do editor: os atalhos seguem
    // por mensagem, e o editor trata-os como "com o foco em lado nenhum".
    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if (event.isComposing || event.repeat || event.altKey) {
                return;
            }

            const target = event.target as Element | null;

            if (
                target?.closest?.(
                    'input, textarea, select, [contenteditable]:not([contenteditable="false"])',
                )
            ) {
                return;
            }

            const key = event.key.toLowerCase();
            const forwarded =
                event.metaKey || event.ctrlKey
                    ? ['c', 'x', 'v', 'd', 'z', 'y'].includes(key)
                    : ['escape', 'delete', 'backspace'].includes(key);

            // Com texto escolhido, copiar é copiar esse texto.
            if (
                !forwarded ||
                ((key === 'c' || key === 'x') &&
                    window.getSelection()?.isCollapsed === false)
            ) {
                return;
            }

            event.preventDefault();
            send({
                source: 'studio-preview',
                kind: 'key',
                key: event.key,
                ctrlKey: event.ctrlKey,
                metaKey: event.metaKey,
                shiftKey: event.shiftKey,
            });
        };

        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, []);

    // ── Desenhar ──────────────────────────────────────────────────────────
    const adders = (parentId: string | null, index: number) => {
        const ask =
            (action: 'add-element' | 'add-structure') =>
            (event: { clientX: number; clientY: number }) =>
                send({
                    source: 'studio-preview',
                    kind: 'action',
                    id: parentId,
                    action,
                    target: { parentId, index },
                    anchor: { x: event.clientX, y: event.clientY },
                });

        return (
            <>
                <AddButton
                    label="Juntar um elemento aqui"
                    onClick={ask('add-element')}
                >
                    <Plus aria-hidden className="size-4" />
                </AddButton>
                <AddButton
                    label="Escolher uma estrutura"
                    onClick={ask('add-structure')}
                >
                    <Columns3 aria-hidden className="size-4" />
                </AddButton>
            </>
        );
    };

    const sectionIndex = boxes.section
        ? (tree.children[ROOT_KEY] ?? []).indexOf(boxes.section.id)
        : -1;
    const selectedBox = boxes.selected;
    const flipped = selectedBox
        ? selectedBox.top < LABEL_HEIGHT * inverse
        : false;
    // À direita da página, a etiqueta cresce para dentro.
    const onRight = selectedBox
        ? selectedBox.left + selectedBox.width / 2 > window.innerWidth / 2
        : false;

    return (
        <>
            <style>{CANVAS_CSS}</style>
            {createPortal(
                <div
                    ref={layer}
                    data-canvas-overlay
                    className="pointer-events-none absolute top-0 left-0 z-50 size-0"
                >
                    {boxes.zones.map(({ id, box }) => {
                        const isEnd = id === ROOT_KEY;

                        return (
                            <div
                                key={id || 'fim'}
                                style={{ ...box, borderWidth: 2 * inverse }}
                                className={cn(
                                    'absolute flex items-center justify-center overflow-hidden rounded-sm border-dashed',
                                    feedback?.inside === id
                                        ? 'border-ring bg-ring/10'
                                        : 'border-muted-foreground/50',
                                )}
                            >
                                <div
                                    style={{ transform: `scale(${inverse})` }}
                                    className="flex shrink-0 items-center gap-1.5 rounded-md bg-background/90 p-1 text-sm whitespace-nowrap text-muted-foreground"
                                >
                                    {adders(
                                        isEnd ? null : id,
                                        (tree.children[id] ?? []).length,
                                    )}
                                    {box.width * scale > 300 && (
                                        <span className="px-1">
                                            {isEnd
                                                ? 'Arrasta um elemento para aqui ou escolhe uma estrutura'
                                                : 'Arrasta um elemento para aqui'}
                                        </span>
                                    )}
                                </div>
                            </div>
                        );
                    })}

                    {boxes.hovered && !feedback && (
                        <div
                            style={{
                                ...boxes.hovered,
                                borderWidth: 1.5 * inverse,
                            }}
                            className="absolute border-dashed border-ring"
                        />
                    )}

                    {selectedBox && (
                        <div
                            style={{ ...selectedBox, borderWidth: 2 * inverse }}
                            className="absolute border-solid border-ring"
                        />
                    )}

                    {boxes.section && sectionIndex >= 0 && !feedback && (
                        <div
                            style={{
                                left:
                                    boxes.section.box.left +
                                    boxes.section.box.width / 2,
                                top:
                                    boxes.section.box.top +
                                    boxes.section.box.height,
                                transform: `scale(${inverse}) translate(-50%, -50%)`,
                                transformOrigin: '0 0',
                            }}
                            className="absolute flex items-center gap-1 rounded-full bg-background p-0.5 shadow-md ring-1 ring-border"
                        >
                            {adders(null, sectionIndex + 1)}
                        </div>
                    )}

                    {selected && selectedBox && (
                        <div
                            ref={label}
                            style={{
                                left: onRight
                                    ? selectedBox.left + selectedBox.width
                                    : selectedBox.left,
                                top: selectedBox.top,
                                transform: `scale(${inverse}) translate(${onRight ? '-100%' : '0'}, ${flipped ? '0' : '-100%'})`,
                                transformOrigin: '0 0',
                            }}
                            className="pointer-events-auto absolute flex items-center gap-0.5 rounded-sm bg-foreground px-0.5 text-xs whitespace-nowrap text-background shadow-md"
                        >
                            <span
                                data-pb-handle={selected}
                                draggable
                                aria-label="Arrastar para mudar de sítio"
                                title="Arrastar para mudar de sítio"
                                className="flex size-6 cursor-grab items-center justify-center active:cursor-grabbing"
                            >
                                <GripVertical aria-hidden className="size-4" />
                            </span>
                            <span className="max-w-48 truncate pr-1">
                                {tree.labels[selected] ?? 'Elemento'}
                            </span>
                            <LabelButton
                                label="Duplicar"
                                onClick={() =>
                                    send({
                                        source: 'studio-preview',
                                        kind: 'action',
                                        id: selected,
                                        action: 'duplicate',
                                    })
                                }
                            >
                                <CopyPlus aria-hidden className="size-3.5" />
                            </LabelButton>
                            <LabelButton
                                label="Apagar"
                                onClick={() =>
                                    send({
                                        source: 'studio-preview',
                                        kind: 'action',
                                        id: selected,
                                        action: 'remove',
                                    })
                                }
                            >
                                <X aria-hidden className="size-3.5" />
                            </LabelButton>
                        </div>
                    )}

                    {feedback?.line && (
                        <div
                            style={
                                feedback.line.orientation === 'horizontal'
                                    ? {
                                          left: feedback.line.x,
                                          top: feedback.line.y,
                                          width: feedback.line.length,
                                          height: 3 * inverse,
                                          transform: 'translateY(-50%)',
                                      }
                                    : {
                                          left: feedback.line.x,
                                          top: feedback.line.y,
                                          height: feedback.line.length,
                                          width: 3 * inverse,
                                          transform: 'translateX(-50%)',
                                      }
                            }
                            className="absolute rounded-full bg-ring"
                        />
                    )}
                </div>,
                host,
            )}
        </>
    );
}

function AddButton({
    label,
    onClick,
    children,
}: {
    label: string;
    onClick: (event: { clientX: number; clientY: number }) => void;
    children: ReactNode;
}) {
    return (
        <button
            type="button"
            aria-label={label}
            title={label}
            onClick={onClick}
            className="pointer-events-auto flex size-7 cursor-pointer items-center justify-center rounded-full bg-foreground text-background transition-opacity hover:opacity-80"
        >
            {children}
        </button>
    );
}

function LabelButton({
    label,
    onClick,
    children,
}: {
    label: string;
    onClick: () => void;
    children: ReactNode;
}) {
    return (
        <button
            type="button"
            aria-label={label}
            title={label}
            onClick={onClick}
            className="flex size-6 cursor-pointer items-center justify-center rounded-sm hover:bg-background/20"
        >
            {children}
        </button>
    );
}
