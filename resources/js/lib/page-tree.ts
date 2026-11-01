import { randomId } from '@/lib/utils';
import type {
    BlockSchema,
    ContainerSchema,
    EditorBlock,
    EditorContainer,
    EditorNode,
} from '@/types/page-builder';
import type {
    StoreContainer,
    StoreDeviceValues,
    StoreNode,
} from '@/types/store';

/** O `type` de um elemento é uma string livre: só o `container` tem filhos. */
export function isContainer(node: StoreNode): node is StoreContainer {
    return node.type === 'container';
}

/**
 * Os valores de uma propriedade nos três tamanhos, com a herança feita: o
 * tablet herda do computador e o telemóvel do tablet. O `fallback` vale para
 * o computador quando o dono não o definiu.
 */
export function resolveDevices<T>(
    v: StoreDeviceValues<T> | undefined,
    fallback?: T,
): StoreDeviceValues<T> {
    const desktop = v?.desktop ?? fallback;
    const tablet = v?.tablet ?? desktop;
    const mobile = v?.mobile ?? tablet;

    return { desktop, tablet, mobile };
}

// ── A árvore do editor ───────────────────────────────────────────────────
// Tudo imutável: o que não muda mantém a referência, e uma operação que não
// faz nada devolve os mesmos `nodes` (não conta para o desfazer).

/**
 * Onde um nó vai cair. `index` é o intervalo na lista de filhos tal como está
 * agora (0 = antes do primeiro, length = no fim), contando com o próprio nó
 * num `moveNode` dentro do mesmo pai: é o que a linha de largar mostra.
 */
export type NodeTarget = { parentId: string | null; index: number };

export type TreeLimits = ContainerSchema['limits'];

export type SchemaByType = Record<string, BlockSchema | undefined>;

export function isEditorContainer(node: EditorNode): node is EditorContainer {
    return node.type === 'container';
}

function blankShell() {
    return {
        type: 'container' as const,
        v: 2,
        visible: true,
        label: null,
        settings: {},
        content: {},
        advanced: {},
    };
}

/** Uma coluna: um contentor em coluna com a largura em percentagem. */
function blankColumn(width: number): EditorContainer {
    return {
        ...blankShell(),
        id: randomId(),
        style: {
            direction: { desktop: 'column' },
            width: { desktop: width },
        },
        children: [],
    };
}

/** Um contentor novo; com mais de uma coluna, as colunas vêm lá dentro. */
export function blankContainer(columns: number[]): EditorContainer {
    if (columns.length <= 1) {
        return {
            ...blankShell(),
            id: randomId(),
            style: {
                direction: { desktop: 'column' },
                max_width: { desktop: 'contained' },
            },
            children: [],
        };
    }

    return {
        ...blankShell(),
        id: randomId(),
        style: {
            // No telemóvel as colunas empilham (o NodeTree dá-lhes 100%).
            direction: { desktop: 'row', mobile: 'column' },
            max_width: { desktop: 'contained' },
        },
        children: columns.map(blankColumn),
    };
}

/** As larguras de 2 a 4 colunas iguais (inteiros, como o servidor guarda). */
const EVEN_COLUMNS: Record<number, number[]> = {
    2: [50, 50],
    3: [33, 33, 34],
    4: [25, 25, 25, 25],
};

/** Quantas colunas o contentor mostra no computador. */
export function columnCount(node: EditorContainer): number {
    return node.style.direction?.desktop === 'row' ? node.children.length : 1;
}

/** Como o NodeTree: uma linha que passa a coluna no telemóvel. */
export function stacksOnMobile(node: EditorContainer): boolean {
    const direction = node.style.direction;

    return (
        resolveDevices(direction).tablet === 'row' &&
        (direction?.mobile ?? 'column') === 'column'
    );
}

/**
 * O mesmo contentor com `count` colunas, sem perder nada: o que estava solto
 * entra na primeira coluna, e o conteúdo das colunas que sobram passa para a
 * última que fica. Com uma só, os filhos das colunas sobem para o contentor.
 * Devolve o próprio nó se já estiver assim.
 */
export function withColumns(
    node: EditorContainer,
    count: number,
): EditorContainer {
    const direction = node.style.direction ?? {};
    const isRow = direction.desktop === 'row';

    if (count <= 1) {
        return isRow
            ? {
                  ...node,
                  style: { ...node.style, direction: { desktop: 'column' } },
                  children: node.children.flatMap((child) =>
                      isEditorContainer(child) ? child.children : [child],
                  ),
              }
            : node;
    }

    const allColumns = node.children.every(isEditorContainer);

    if (isRow && allColumns && node.children.length === count) {
        return node;
    }

    const widths = EVEN_COLUMNS[count] ?? [];
    const current =
        isRow && allColumns
            ? (node.children as EditorContainer[])
            : node.children.length > 0
              ? [{ ...blankColumn(widths[0]), children: node.children }]
              : [];
    const kept = current.slice(0, count);
    const extra = current.slice(count).flatMap((column) => column.children);

    return {
        ...node,
        style: {
            ...node.style,
            direction: {
                ...direction,
                desktop: 'row',
                mobile: direction.mobile ?? 'column',
            },
        },
        children: widths.map((width, index) => {
            const column = kept[index] ?? blankColumn(width);

            return {
                ...column,
                style: {
                    ...column.style,
                    width: { ...column.style.width, desktop: width },
                },
                children:
                    index === kept.length - 1
                        ? [...column.children, ...extra]
                        : column.children,
            };
        }),
    };
}

/** Uma cópia com ids novos em toda a profundidade. */
export function duplicateNode(node: EditorNode): EditorNode {
    const renew = (original: EditorNode): EditorNode =>
        isEditorContainer(original)
            ? {
                  ...original,
                  id: randomId(),
                  children: original.children.map(renew),
              }
            : { ...original, id: randomId() };

    return renew(structuredClone(node));
}

/** A árvore toda em pré-ordem. */
export function walkNodes(nodes: EditorNode[]): EditorNode[] {
    return nodes.flatMap((node) =>
        isEditorContainer(node) ? [node, ...walkNodes(node.children)] : [node],
    );
}

/**
 * `depth` são os contentores acima do nó, como no BlockSanitizer: um contentor
 * de raiz tem 0 e o elemento lá dentro tem 1.
 */
export function findNode(
    nodes: EditorNode[],
    id: string,
    parent: EditorContainer | null = null,
    depth = 0,
): {
    node: EditorNode;
    parent: EditorContainer | null;
    index: number;
    depth: number;
} | null {
    for (const [index, node] of nodes.entries()) {
        if (node.id === id) {
            return { node, parent, index, depth };
        }

        if (isEditorContainer(node)) {
            const found = findNode(node.children, id, node, depth + 1);

            if (found) {
                return found;
            }
        }
    }

    return null;
}

/** Os nós da raiz até ao próprio, inclusive; vazio se não existir. */
export function nodePath(nodes: EditorNode[], id: string): EditorNode[] {
    for (const node of nodes) {
        if (node.id === id) {
            return [node];
        }

        if (isEditorContainer(node)) {
            const below = nodePath(node.children, id);

            if (below.length > 0) {
                return [node, ...below];
            }
        }
    }

    return [];
}

/** O PHP manda um mapa vazio como `[]`; o que falta passa a mapa vazio. */
function asMap<T extends object>(value: T | undefined): T {
    return typeof value === 'object' && value !== null && !Array.isArray(value)
        ? value
        : ({} as T);
}

/** Um nó de um rascunho antigo, com as chaves que o editor conta encontrar. */
function normalizeNode(raw: EditorNode): EditorNode {
    if (isEditorContainer(raw)) {
        return {
            ...raw,
            label: raw.label ?? null,
            settings: asMap(raw.settings),
            content: asMap(raw.content),
            style: asMap(raw.style),
            advanced: asMap(raw.advanced),
            children: Array.isArray(raw.children)
                ? raw.children.map(normalizeNode)
                : [],
        };
    }

    return {
        ...raw,
        label: raw.label ?? null,
        // Como no BlockSanitizer: sem o valor, a casca está ligada.
        shell: raw.shell ?? true,
        settings: asMap(raw.settings),
        content: asMap(raw.content),
        style: asMap(raw.style),
        styleProps: asMap(raw.styleProps),
        advanced: asMap(raw.advanced),
    };
}

/**
 * O que o editor recebe do servidor, tal como está na BD. Um rascunho antigo
 * (v1) ainda não gravado: cada bloco solto ganha o contentor transparente que
 * o PageDocument::wrapper() lhe daria, e os nós ganham as chaves em falta.
 */
export function upgradeRoots(nodes: EditorNode[]): EditorNode[] {
    return nodes
        .map((node): EditorNode =>
            isEditorContainer(node)
                ? node
                : {
                      id: `c-${node.id}`,
                      type: 'container',
                      v: 2,
                      visible: true,
                      label: null,
                      settings: {},
                      content: {},
                      style: {},
                      advanced: {},
                      children: [node],
                  },
        )
        .map(normalizeNode);
}

function spliced(
    list: EditorNode[],
    node: EditorNode,
    index: number,
): EditorNode[] {
    const next = [...list];
    next.splice(Math.max(0, Math.min(index, list.length)), 0, node);

    return next;
}

function mapNode(
    nodes: EditorNode[],
    id: string,
    change: (node: EditorNode) => EditorNode,
): EditorNode[] {
    let changed = false;
    const next = nodes.map((node) => {
        if (node.id === id) {
            const result = change(node);
            changed ||= result !== node;

            return result;
        }

        if (isEditorContainer(node)) {
            const children = mapNode(node.children, id, change);

            if (children !== node.children) {
                changed = true;

                return { ...node, children };
            }
        }

        return node;
    });

    return changed ? next : nodes;
}

/**
 * Mete um nó no alvo. Na raiz só há contentores: um elemento ganha um. Uma
 * casca de secção já traz a largura e o fundo, por isso esse contentor fica
 * neutro (largura toda), como o das páginas convertidas.
 */
export function insertNode(
    nodes: EditorNode[],
    node: EditorNode,
    target: NodeTarget,
): EditorNode[] {
    if (target.parentId === null) {
        if (isEditorContainer(node)) {
            return spliced(nodes, node, target.index);
        }

        const wrapper = blankContainer([100]);

        if (node.shell) {
            wrapper.style = {
                ...wrapper.style,
                max_width: { desktop: 'full' },
            };
        }

        return spliced(nodes, { ...wrapper, children: [node] }, target.index);
    }

    return mapNode(nodes, target.parentId, (parent) =>
        isEditorContainer(parent)
            ? {
                  ...parent,
                  children: spliced(parent.children, node, target.index),
              }
            : parent,
    );
}

export function removeNode(nodes: EditorNode[], id: string): EditorNode[] {
    let changed = false;
    const next: EditorNode[] = [];

    for (const node of nodes) {
        if (node.id === id) {
            changed = true;

            continue;
        }

        if (isEditorContainer(node)) {
            const children = removeNode(node.children, id);

            if (children !== node.children) {
                changed = true;
                next.push({ ...node, children });

                continue;
            }
        }

        next.push(node);
    }

    return changed ? next : nodes;
}

/**
 * Muda o nó de sítio. Um alvo impossível (o próprio nó, um descendente, um pai
 * que não existe ou não é contentor) devolve a árvore como estava: nunca se
 * perde nada, mesmo que quem chama não tenha perguntado ao `canDrop`.
 */
export function moveNode(
    nodes: EditorNode[],
    id: string,
    target: NodeTarget,
): EditorNode[] {
    const found = findNode(nodes, id);

    if (!found) {
        return nodes;
    }

    if (target.parentId !== null) {
        const parent = findNode(nodes, target.parentId)?.node;

        if (
            !parent ||
            !isEditorContainer(parent) ||
            findNode([found.node], target.parentId)
        ) {
            return nodes;
        }
    }

    const sameParent = (found.parent?.id ?? null) === target.parentId;

    if (
        sameParent &&
        (target.index === found.index || target.index === found.index + 1)
    ) {
        return nodes;
    }

    return insertNode(removeNode(nodes, id), found.node, {
        parentId: target.parentId,
        index:
            sameParent && found.index < target.index
                ? target.index - 1
                : target.index,
    });
}

/**
 * Junta o `patch` ao nó, tal e qual (um `false` explícito fica); o id e o tipo
 * nunca mudam.
 */
export function updateNode(
    nodes: EditorNode[],
    id: string,
    patch: Partial<EditorContainer> | Partial<EditorBlock>,
): EditorNode[] {
    return mapNode(
        nodes,
        id,
        (node) =>
            ({ ...node, ...patch, id: node.id, type: node.type }) as EditorNode,
    );
}

/** Uma cópia logo a seguir ao original; `newId` é null se ele não existir. */
export function duplicateInPlace(
    nodes: EditorNode[],
    id: string,
): { nodes: EditorNode[]; newId: string | null } {
    const found = findNode(nodes, id);

    if (!found) {
        return { nodes, newId: null };
    }

    const copy = duplicateNode(found.node);

    return {
        nodes: insertNode(nodes, copy, {
            parentId: found.parent?.id ?? null,
            index: found.index + 1,
        }),
        newId: copy.id,
    };
}

/** Quantos contentores encaixados há no nó (um elemento tem 0). */
function containerHeight(node: EditorNode): number {
    return isEditorContainer(node)
        ? 1 + Math.max(0, ...node.children.map(containerHeight))
        : 0;
}

/** As mesmas regras do BlockSanitizer, ditas antes de largar. */
function refusal(
    nodes: EditorNode[],
    subject: EditorNode,
    target: NodeTarget,
    schemaByType: SchemaByType,
    limits: TreeLimits,
    movingId: string | null,
): string | null {
    const found =
        target.parentId === null ? null : findNode(nodes, target.parentId);

    if (target.parentId !== null && !found) {
        return 'O sítio onde ias largar já não existe.';
    }

    const parent = found?.node ?? null;

    if (parent && !isEditorContainer(parent)) {
        return 'Só os contentores levam outros elementos lá dentro.';
    }

    if (movingId !== null && parent && findNode([subject], parent.id)) {
        return 'Um contentor não pode entrar dentro de si mesmo.';
    }

    const siblings = (parent?.children ?? []).filter(
        (child) => child.id !== movingId,
    );
    const wrapped = parent === null && !isEditorContainer(subject);
    const depth = found ? found.depth + 1 : wrapped ? 1 : 0;

    if (depth + containerHeight(subject) > limits.maxDepth) {
        return `Não se pode meter mais de ${limits.maxDepth} contentores uns dentro dos outros.`;
    }

    if (
        parent?.style.direction?.desktop === 'row' &&
        siblings.length >= limits.maxRowChildren
    ) {
        return `Um contentor em linha leva no máximo ${limits.maxRowChildren} elementos lado a lado.`;
    }

    const added =
        (movingId === null ? walkNodes([subject]).length : 0) +
        (wrapped ? 1 : 0);

    if (walkNodes(nodes).length + added > limits.maxNodes) {
        return `Uma página não pode ter mais de ${limits.maxNodes} elementos e contentores.`;
    }

    // Quem já lá está e tem de ficar sozinho.
    for (const sibling of siblings) {
        const schema = schemaByType[sibling.type];

        if (schema?.rootOnly) {
            return `O bloco "${schema.label}" só pode ficar sozinho numa secção da página.`;
        }
    }

    // Ao mudar de sítio, os tipos da sub-árvore não se repetem: ela sai.
    const used =
        movingId === null
            ? new Set(walkNodes(nodes).map((node) => node.type))
            : new Set<string>();

    const visit = (
        node: EditorNode,
        at: number,
        alongside: number,
    ): string | null => {
        if (isEditorContainer(node)) {
            for (const child of node.children) {
                const message = visit(child, at + 1, node.children.length);

                if (message) {
                    return message;
                }
            }

            return null;
        }

        const schema = schemaByType[node.type];

        if (!schema) {
            return `O bloco "${node.type}" não pode entrar nesta página.`;
        }

        if (schema.unique && used.has(node.type)) {
            return `Só pode haver um bloco "${schema.label}" por página.`;
        }

        used.add(node.type);

        if (schema.rootOnly && (at !== 1 || alongside !== 1)) {
            return `O bloco "${schema.label}" só pode ficar sozinho numa secção da página.`;
        }

        return null;
    };

    return visit(subject, depth, siblings.length + 1);
}

/**
 * Null se se pode largar; senão, a mensagem para o dono. Arrasta-se um nó que
 * já está na página (o id) ou um tipo novo (`{type}`). Uma estrutura com
 * colunas tem mais do que um nível: essa pergunta-se ao `canPaste`.
 */
export function canDrop(
    nodes: EditorNode[],
    dragged: string | { type: string },
    target: NodeTarget,
    schemaByType: SchemaByType,
    limits: TreeLimits,
): string | null {
    if (typeof dragged !== 'string') {
        const subject =
            dragged.type === 'container'
                ? blankContainer([100])
                : ({ type: dragged.type } as EditorBlock);

        return refusal(nodes, subject, target, schemaByType, limits, null);
    }

    const found = findNode(nodes, dragged);

    return found
        ? refusal(nodes, found.node, target, schemaByType, limits, dragged)
        : 'Este elemento já não está na página.';
}

/** Igual ao `canDrop`, para uma sub-árvore que entra de novo (colar). */
export function canPaste(
    nodes: EditorNode[],
    node: EditorNode,
    target: NodeTarget,
    schemaByType: SchemaByType,
    limits: TreeLimits,
): string | null {
    return refusal(nodes, node, target, schemaByType, limits, null);
}

// ── Erros do servidor ────────────────────────────────────────────────────
// O servidor diz "blocks.0.children.1.content.pt.title", com as posições da
// árvore que recebeu. Guardam-se por id, para o aviso não saltar de nó ao
// reordenar ou desfazer.

export function errorKey(id: string): string {
    return `node:${id}`;
}

function nodeErrorPath(sent: EditorNode[], path: string): string | null {
    const parts = path.split('.');

    if (parts[0] !== 'blocks' || !/^\d+$/.test(parts[1] ?? '')) {
        return null;
    }

    let node = sent[Number(parts[1])];
    let at = 2;

    if (!node) {
        return null;
    }

    while (
        parts[at] === 'children' &&
        /^\d+$/.test(parts[at + 1] ?? '') &&
        isEditorContainer(node) &&
        node.children[Number(parts[at + 1])]
    ) {
        node = node.children[Number(parts[at + 1])];
        at += 2;
    }

    return [errorKey(node.id), ...parts.slice(at)].join('.');
}

/**
 * Troca os caminhos `blocks.N(.children.N)*` por `node:<id>` e o resto do
 * caminho, a partir da árvore que foi enviada. O que não aponta para um nó
 * (`blocks`, `seo.pt.title`) fica como veio.
 */
export function keyErrorsByNode(
    sent: EditorNode[],
    errors: Record<string, string>,
): Record<string, string> {
    return Object.fromEntries(
        Object.entries(errors).map(([path, message]) => [
            nodeErrorPath(sent, path) ?? path,
            message,
        ]),
    );
}

/** Os ids dos nós com algum erro. */
export function erroredNodeIds(errors: Record<string, string>): Set<string> {
    const ids = new Set<string>();

    for (const key of Object.keys(errors)) {
        const match = key.match(/^node:([^.]+)/);

        if (match) {
            ids.add(match[1]);
        }
    }

    return ids;
}
