import type { NodeTarget } from '@/lib/page-tree';
import type { PreviewMessage } from '@/types/page-builder';

/**
 * O que corre dos dois lados do iframe: não importa nada do backoffice, porque
 * vai no pedaço de código da pré-visualização.
 */

/** O tipo que vai no `dataTransfer` ao arrastar da grelha. */
export const ELEMENT_DRAG_TYPE = 'application/x-studio-element';

/** O mesmo, ao arrastar um nó da página pela pega da etiqueta. */
export const NODE_DRAG_TYPE = 'application/x-studio-node';

/** A chave da raiz no mapa dos filhos da árvore. */
export const ROOT_KEY = '';

/** O que o iframe pede ao editor (o resto das mensagens fica no PreviewFrame). */
export type CanvasMessage = Extract<
    PreviewMessage,
    { kind: 'drop' | 'move' | 'action' }
>;

/** Os nomes para as etiquetas e a ordem a sério dos filhos de cada contentor. */
export type CanvasTree = {
    labels: Record<string, string>;
    children: Record<string, string[]>;
};

export type DropRect = {
    left: number;
    top: number;
    right: number;
    bottom: number;
};

/**
 * Um filho desenhado. `index` é a posição na árvore do editor, que não é a do
 * DOM: os nós escondidos não vêm do servidor nem têm caixa.
 */
export type DropChild = { id: string; rect: DropRect; index: number };

/** Um contentor debaixo do ponteiro; `id: null` é a página. */
export type DropLevel = {
    id: string | null;
    rect: DropRect | null;
    /** Onde os filhos ficam (o interior, num contentor contido). */
    inner: DropRect | null;
    direction: 'row' | 'column';
    /** Quantos filhos tem na árvore, com os escondidos. */
    count: number;
    children: DropChild[];
};

export type DropQuery = {
    /** Da página até ao contentor mais fundo debaixo do ponteiro. */
    levels: DropLevel[];
    /** O elemento debaixo do ponteiro, se houver. */
    element: { id: string; rect: DropRect } | null;
    pointer: { x: number; y: number };
    /** A faixa junto às bordas de um contentor que quer dizer "ao lado dele". */
    edge: number;
    /** O nó a ser arrastado; um elemento novo da grelha não tem. */
    dragId?: string | null;
};

export type DropLine = {
    orientation: 'horizontal' | 'vertical';
    x: number;
    y: number;
    length: number;
};

export type DropResult = {
    target: NodeTarget;
    /** Null só na página vazia, onde a zona do fim é que se acende. */
    line: DropLine | null;
    /** Cai dentro de um contentor sem filhos. */
    inside: boolean;
};

const height = (rect: DropRect) => rect.bottom - rect.top;
const width = (rect: DropRect) => rect.right - rect.left;

/** O intervalo `position` entre os filhos desenhados de um contentor. */
function slot(
    level: DropLevel,
    position: number,
    dragId: string | null,
): DropResult | null {
    const { children } = level;
    const own = dragId
        ? children.findIndex((child) => child.id === dragId)
        : -1;

    // Largar um nó mesmo ao lado de si próprio não faz nada.
    if (own >= 0 && (position === own || position === own + 1)) {
        return null;
    }

    const previous = children[position - 1];
    const next = children[position];
    const anchor = next ?? previous;

    if (!anchor) {
        return null;
    }

    const target = {
        parentId: level.id,
        index: next ? next.index : previous.index + 1,
    };

    if (level.direction === 'row') {
        const x =
            previous && next
                ? (previous.rect.right + next.rect.left) / 2
                : next
                  ? next.rect.left
                  : previous.rect.right;

        return {
            target,
            inside: false,
            line: {
                orientation: 'vertical',
                x,
                y: anchor.rect.top,
                length: height(anchor.rect),
            },
        };
    }

    const y =
        previous && next
            ? (previous.rect.bottom + next.rect.top) / 2
            : next
              ? next.rect.top
              : previous.rect.bottom;

    return {
        target,
        inside: false,
        line: {
            orientation: 'horizontal',
            x: anchor.rect.left,
            y,
            length: width(anchor.rect),
        },
    };
}

/**
 * Onde cai o que se está a arrastar, só a partir de caixas e do ponteiro (tudo
 * no espaço do iframe: o `dragover` lá dentro já vem sem a escala do editor).
 *
 * 1. Junto ao topo ou ao fundo de um contentor empilhado: ao lado dele, do mais
 *    de fora para o mais de dentro. É assim que se chega entre duas secções,
 *    mesmo com os elementos a tocar nas bordas. Numa linha não: uma coluna tem
 *    elementos encostados aos lados, e largar ali é para ir para dentro dela.
 * 2. Num elemento: antes ou depois, conforme a metade, na direção do pai.
 * 3. Num contentor sem filhos: dentro.
 * 4. No espaço livre de um contentor (e fora de todos, na página): o intervalo
 *    mais perto entre os filhos.
 *
 * Null quando não há nada a fazer: um nó por cima de si próprio ou de um
 * descendente, ou mesmo ao lado de onde já está.
 */
export function computeDropTarget({
    levels,
    element,
    pointer,
    edge,
    dragId = null,
}: DropQuery): DropResult | null {
    if (
        dragId !== null &&
        (element?.id === dragId || levels.some((l) => l.id === dragId))
    ) {
        return null;
    }

    for (let at = 1; at < levels.length; at++) {
        const parent = levels[at - 1];
        const { id, rect } = levels[at];

        if (parent.direction !== 'column' || !rect) {
            continue;
        }

        const band = Math.min(edge, height(rect) / 4);
        const before = pointer.y - rect.top < band;
        const after = rect.bottom - pointer.y < band;
        const position = parent.children.findIndex((c) => c.id === id);

        if ((before || after) && position >= 0) {
            return slot(parent, before ? position : position + 1, dragId);
        }
    }

    const deepest = levels[levels.length - 1];
    const along = deepest.direction === 'row' ? pointer.x : pointer.y;

    if (element) {
        const position = deepest.children.findIndex((c) => c.id === element.id);

        if (position >= 0) {
            const middle =
                deepest.direction === 'row'
                    ? element.rect.left + width(element.rect) / 2
                    : element.rect.top + height(element.rect) / 2;

            return slot(
                deepest,
                along < middle ? position : position + 1,
                dragId,
            );
        }
    }

    if (deepest.children.length === 0) {
        const inner = deepest.inner ?? deepest.rect;

        return {
            target: { parentId: deepest.id, index: deepest.count },
            inside: true,
            line: inner
                ? {
                      orientation: 'horizontal',
                      x: inner.left,
                      y: inner.top + height(inner) / 2,
                      length: width(inner),
                  }
                : null,
        };
    }

    const position = deepest.children.findIndex(({ rect }) =>
        deepest.direction === 'row'
            ? along < rect.left + width(rect) / 2
            : along < rect.top + height(rect) / 2,
    );

    return slot(
        deepest,
        position < 0 ? deepest.children.length : position,
        dragId,
    );
}
