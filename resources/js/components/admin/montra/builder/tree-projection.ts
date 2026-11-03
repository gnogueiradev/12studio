import { arrayMove } from '@dnd-kit/sortable';
import { findNode, isEditorContainer } from '@/lib/page-tree';
import type { NodeTarget } from '@/lib/page-tree';
import type { EditorContainer, EditorNode } from '@/types/page-builder';

export type Row = {
    node: EditorNode;
    parent: EditorContainer | null;
    depth: number;
};

/** A árvore em lista, sem o que está fechado nem o que vai com o arrasto. */
export function flatten(
    nodes: EditorNode[],
    closed: Set<string>,
    dragging: string | null,
    parent: EditorContainer | null = null,
    depth = 0,
): Row[] {
    return nodes.flatMap((node) => {
        const row: Row = { node, parent, depth };

        return isEditorContainer(node) &&
            node.id !== dragging &&
            !closed.has(node.id)
            ? [
                  row,
                  ...flatten(node.children, closed, dragging, node, depth + 1),
              ]
            : [row];
    });
}

export type Projection = { depth: number; target: NodeTarget };

/**
 * Onde o arrasto vai cair: a linha sob o ponteiro diz a posição, e o
 * deslocamento na horizontal o nível, dentro do que os vizinhos permitem. O
 * `target.index` é o intervalo na lista como está agora (ver `NodeTarget`).
 */
export function project(
    nodes: EditorNode[],
    rows: Row[],
    closed: Set<string>,
    activeId: string,
    overId: string,
    shift: number,
): Projection | null {
    const from = rows.findIndex((row) => row.node.id === activeId);
    const to = rows.findIndex((row) => row.node.id === overId);

    if (from < 0 || to < 0) {
        return null;
    }

    const active = rows[from];
    const moved = arrayMove(rows, from, to);
    const previous = moved[to - 1];
    const next = moved[to + 1];
    const deepest = !previous
        ? 0
        : isEditorContainer(previous.node)
          ? previous.depth + 1
          : previous.depth;
    const shallowest = next ? next.depth : 0;
    const depth = Math.min(deepest, Math.max(shallowest, active.depth + shift));

    let parent: EditorNode | null = null;

    for (let at = to - 1; depth > 0 && at >= 0; at--) {
        if (moved[at].depth === depth - 1) {
            parent = moved[at].node;

            break;
        }
    }

    const parentId = parent?.id ?? null;

    if (parent && isEditorContainer(parent) && closed.has(parent.id)) {
        return {
            depth,
            target: { parentId, index: parent.children.length },
        };
    }

    const before = moved
        .slice(0, to)
        .filter((row) => (row.parent?.id ?? null) === parentId).length;
    const current = findNode(nodes, activeId);
    const sameParent = (current?.parent?.id ?? null) === parentId;

    return {
        depth,
        target: {
            parentId,
            index:
                sameParent && current && current.index <= before
                    ? before + 1
                    : before,
        },
    };
}
