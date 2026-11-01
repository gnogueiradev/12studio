import { normalizeNode } from '@/lib/page-tree';
import type { EditorNode } from '@/types/page-builder';

const KEY = 'studio-clipboard';

/** Só em memória se o browser não deixar usar o localStorage. */
let memory: string | null = null;

/** Copia a sub-árvore; serve também para colar noutra página. */
export function copyNode(node: EditorNode): void {
    memory = JSON.stringify({ v: 2, node });

    try {
        window.localStorage.setItem(KEY, memory);
    } catch {
        // Fica a cópia em memória.
    }
}

function isNode(value: unknown): value is EditorNode {
    const node = value as Partial<EditorNode> | null;

    return (
        typeof node === 'object' &&
        node !== null &&
        typeof node.id === 'string' &&
        typeof node.type === 'string' &&
        (node.type !== 'container' ||
            (Array.isArray((node as { children?: unknown }).children) &&
                (node as { children: unknown[] }).children.every(isNode)))
    );
}

/** O que está copiado; null se não houver nada ou não se perceber. */
export function readClipboard(): EditorNode | null {
    let raw = memory;

    try {
        raw = window.localStorage.getItem(KEY) ?? memory;
    } catch {
        // Fica a cópia em memória.
    }

    if (!raw) {
        return null;
    }

    try {
        const parsed = JSON.parse(raw) as { v?: unknown; node?: unknown };

        // Pode ter vindo de outra versão, ou ter sido mexido à mão: chega ao
        // editor com todas as chaves que ele conta encontrar.
        return parsed.v === 2 && isNode(parsed.node)
            ? normalizeNode(parsed.node)
            : null;
    } catch {
        return null;
    }
}
