import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowLeft,
    CalendarClock,
    Copy,
    ExternalLink,
    EyeOff,
    History,
    ListTree,
    MoreHorizontal,
    Redo2,
    RotateCcw,
    Search,
    Trash2,
    Undo2,
} from 'lucide-react';
import {
    useCallback,
    useEffect,
    useEffectEvent,
    useMemo,
    useRef,
    useState,
    useSyncExternalStore,
} from 'react';
import type { ReactNode } from 'react';
import { toast } from 'sonner';
import { ConfirmDialog } from '@/components/admin/confirm-dialog';
import {
    blankContainer,
    blankNode,
    duplicateNode,
    nodeLabel,
    subtreeFlags,
} from '@/components/admin/montra/blocks';
import {
    copyNode,
    readClipboard,
} from '@/components/admin/montra/builder/clipboard';
import { ROOT_KEY } from '@/components/admin/montra/builder/dnd-protocol';
import type {
    CanvasMessage,
    CanvasTree,
} from '@/components/admin/montra/builder/dnd-protocol';
import { EditPanel } from '@/components/admin/montra/builder/edit-panel';
import type { StyleOptions } from '@/components/admin/montra/builder/edit-panel';
import { ElementsPanel } from '@/components/admin/montra/builder/elements-panel';
import { Navigator } from '@/components/admin/montra/builder/navigator';
import type { PasteWhere } from '@/components/admin/montra/builder/navigator';
import { StructurePicker } from '@/components/admin/montra/builder/structure-picker';
import type { FieldContext } from '@/components/admin/montra/field-input';
import {
    HistorySheet,
    LandingSettingsDialog,
    SeoDialog,
} from '@/components/admin/montra/page-settings';
import { PageStatusBadge } from '@/components/admin/montra/page-status';
import {
    PreviewControls,
    PreviewFrame,
} from '@/components/admin/montra/preview-frame';
import type { Device } from '@/components/admin/montra/preview-frame';
import { MOD, ShortcutsHint } from '@/components/admin/montra/shortcuts';
import { useHistory } from '@/components/admin/montra/use-block-history';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Spinner } from '@/components/ui/spinner';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useIsWide } from '@/hooks/use-wide';
import { HttpError, requestJson } from '@/lib/http';
import {
    canDrop,
    canPaste,
    duplicateInPlace,
    errorKey,
    findNode,
    insertNode,
    isEditorContainer,
    keyErrorsByNode,
    walkNodes,
    moveNode,
    nodePath,
    removeNode,
    updateNode,
    upgradeRoots,
    withColumns,
} from '@/lib/page-tree';
import type { NodeTarget } from '@/lib/page-tree';
import { cn } from '@/lib/utils';
import {
    descartar,
    despublicar,
    destroy,
    duplicar,
    index as pagesIndex,
    publicar,
    rascunho,
    render,
} from '@/routes/admin/montra/paginas';
import type {
    BlockSchema,
    ContainerSchema,
    EditablePage,
    EditorNode,
    ProductOption,
    Revision,
    StoreLink,
} from '@/types/page-builder';

type Props = {
    page: EditablePage;
    schema: BlockSchema[];
    container: ContainerSchema;
    styles: StyleOptions;
    revisions: Revision[];
    locales: string[];
    previewUrl: string;
    publicUrl: string | null;
    links: StoreLink[];
    categories: { value: number; label: string }[];
    tags: { value: number; label: string }[];
    pickedProducts: ProductOption[];
    media: Record<number, string>;
};

type SaveState = 'saved' | 'dirty' | 'saving' | 'error';

const AUTOSAVE_DELAY = 1200;

const NAVIGATOR_KEY = 'studio-editor-navigator';

/** Só em memória se o browser não deixar usar o localStorage. */
let navigatorMemory: string | null = null;
const navigatorListeners = new Set<() => void>();

function readNavigatorChoice(): string | null {
    try {
        return window.localStorage.getItem(NAVIGATOR_KEY) ?? navigatorMemory;
    } catch {
        return navigatorMemory;
    }
}

function saveNavigatorChoice(open: boolean) {
    navigatorMemory = open ? '1' : '0';

    try {
        window.localStorage.setItem(NAVIGATOR_KEY, navigatorMemory);
    } catch {
        // Fica em memória.
    }

    navigatorListeners.forEach((listener) => listener());
}

function subscribeNavigatorChoice(listener: () => void) {
    navigatorListeners.add(listener);

    return () => navigatorListeners.delete(listener);
}

/**
 * O editor de uma página da montra, no ecrã todo: Elementos e Editar à
 * esquerda, pré-visualização ao centro, Navegador à direita (abre e fecha).
 * Tudo grava sozinho em rascunho; o site só muda em "Publicar".
 */
export default function PageEditor(props: Props) {
    const { page, schema, locales, revisions } = props;
    const defaultLocale = locales[0];
    const wide = useIsWide();

    const limits = props.container.limits;
    // Uma só vez: percorre a árvore toda.
    const [initialNodes] = useState(() => upgradeRoots(page.blocks));

    const {
        present: nodes,
        set: setNodes,
        undo,
        redo,
        reset: resetBlocks,
        canUndo,
        canRedo,
    } = useHistory<EditorNode[]>(initialNodes);
    const [seo, setSeo] = useState(page.seo);
    const [selected, setSelected] = useState<string | null>(null);
    const [locale, setLocale] = useState(defaultLocale);
    const [saveState, setSaveState] = useState<SaveState>('saved');
    const [errors, setErrors] = useState<Record<string, string>>({});
    // O estado de publicação: o do servidor, até o autosave trazer um mais
    // recente. Uma visita nova (publicar, repor) volta a pôr o do servidor.
    const serverStatus = {
        status: page.status,
        hasChanges: page.hasChanges,
        live: page.live,
    };
    const serverKey = JSON.stringify(serverStatus);
    const [seenServerKey, setSeenServerKey] = useState(serverKey);
    const [savedStatus, setStatus] = useState<typeof serverStatus | null>(null);

    if (seenServerKey !== serverKey) {
        setSeenServerKey(serverKey);
        setStatus(null);
    }

    const status = savedStatus ?? serverStatus;
    const [products, setProducts] = useState<Record<number, ProductOption>>(
        () =>
            Object.fromEntries(
                props.pickedProducts.map((product) => [product.id, product]),
            ),
    );
    const [mediaSrc, setMediaSrc] = useState<Record<number, string>>(
        props.media,
    );
    const [device, setDevice] = useState<Device>('desktop');
    // O painel esquerdo; abaixo de lg, o que ocupa o ecrã.
    const [panel, setPanel] = useState<'elements' | 'edit'>('elements');
    const [mobileView, setMobileView] = useState<
        'panel' | 'navigator' | 'preview'
    >('panel');
    // O tipo a ser arrastado da grelha para a página.
    const [draggingType, setDraggingType] = useState<string | null>(null);
    // O sítio que o "+" da página guardou para o próximo elemento da grelha.
    const [insertTarget, setInsertTarget] = useState<NodeTarget | null>(null);
    // O "▥": a escolher a estrutura para este sítio.
    const [structurePicker, setStructurePicker] = useState<{
        target: NodeTarget;
        anchor: { x: number; y: number };
    } | null>(null);
    const closeStructurePicker = useCallback(
        () => setStructurePicker(null),
        [],
    );
    const navigatorDragging = useRef(false);
    // O que o dono escolheu; sem escolha, aberto só nos ecrãs largos.
    const navigatorChoice = useSyncExternalStore(
        subscribeNavigatorChoice,
        readNavigatorChoice,
        () => null,
    );
    const navigatorOpen = navigatorChoice ? navigatorChoice === '1' : wide;
    const [seoOpen, setSeoOpen] = useState(false);
    const [settingsOpen, setSettingsOpen] = useState(false);
    const [historyOpen, setHistoryOpen] = useState(false);
    const [confirmDelete, setConfirmDelete] = useState(false);
    const [publishing, setPublishing] = useState(false);

    const schemaByType = useMemo(
        () => Object.fromEntries(schema.map((block) => [block.type, block])),
        [schema],
    );

    // ── Autosave ──────────────────────────────────────────────────────────
    // Cada alteração sobe a versão; uma gravação só diz "guardado" se nada
    // mudou enquanto ia ao servidor. As gravações vão em fila — nunca duas ao
    // mesmo tempo, para uma resposta antiga não pisar uma mais recente.
    const first = useRef(true);
    // Quantas vezes a página foi reposta do servidor (repor uma versão,
    // descartar o rascunho).
    const resets = useRef(0);
    const version = useRef(0);
    const savedVersion = useRef(0);
    const queue = useRef<Promise<boolean>>(Promise.resolve(true));
    const latest = useRef({ blocks: nodes, seo });

    useEffect(() => {
        latest.current = { blocks: nodes, seo };
    }, [nodes, seo]);

    const saveNow = useCallback(async (): Promise<boolean> => {
        const sending = version.current;
        // Os erros voltam com as posições desta árvore, não as de agora.
        const sent = latest.current;
        setSaveState('saving');

        try {
            const response = await requestJson<{
                page: {
                    status: EditablePage['status'];
                    hasChanges: boolean;
                    live: boolean;
                };
            }>(rascunho(page.id).url, 'PUT', sent);
            savedVersion.current = Math.max(savedVersion.current, sending);
            setStatus({
                status: response.page.status,
                hasChanges: response.page.hasChanges,
                live: response.page.live,
            });
            setErrors({});
            setSaveState(version.current === sending ? 'saved' : 'dirty');

            return true;
        } catch (exception) {
            if (exception instanceof HttpError && exception.status === 422) {
                setErrors(
                    keyErrorsByNode(
                        sent.blocks,
                        Object.fromEntries(
                            Object.entries(exception.body.errors ?? {}).map(
                                ([key, messages]) => [key, messages[0]],
                            ),
                        ),
                    ),
                );
            }

            setSaveState('error');

            return false;
        }
    }, [page.id]);

    const save = useCallback((): Promise<boolean> => {
        queue.current = queue.current.then(saveNow, saveNow);

        return queue.current;
    }, [saveNow]);

    useEffect(() => {
        if (first.current) {
            first.current = false;

            return;
        }

        version.current += 1;
        setSaveState('dirty');
        const timer = window.setTimeout(() => {
            void save();
        }, AUTOSAVE_DELAY);

        return () => window.clearTimeout(timer);
    }, [nodes, seo, save]);

    /** Grava já o que estiver por gravar (antes de publicar ou sair). */
    const flush = useCallback(async (): Promise<boolean> => {
        const ok = await queue.current;

        if (savedVersion.current !== version.current) {
            return save();
        }

        return ok;
    }, [save]);

    // Sair por um link do backoffice (que não passa pelo beforeunload):
    // grava primeiro e só depois segue. Se não der para gravar, pergunta.
    const leaving = useRef(false);
    useEffect(
        () =>
            router.on('before', (event) => {
                const visit = event.detail.visit;

                if (
                    leaving.current ||
                    visit.method !== 'get' ||
                    visit.only.length > 0 ||
                    savedVersion.current === version.current
                ) {
                    return;
                }

                event.preventDefault();
                void flush().then((ok) => {
                    if (
                        ok ||
                        window.confirm(
                            'Há alterações que não foi possível guardar. Sair na mesma?',
                        )
                    ) {
                        leaving.current = true;
                        router.visit(visit.url.href);
                    }
                });
            }),
        [flush],
    );

    useEffect(() => {
        const warn = (event: BeforeUnloadEvent) => {
            if (savedVersion.current !== version.current) {
                event.preventDefault();
            }
        };

        window.addEventListener('beforeunload', warn);

        return () => window.removeEventListener('beforeunload', warn);
    }, [saveState]);

    // ── Operações na árvore ───────────────────────────────────────────────
    // Escrever num campo junta-se num só passo do desfazer (por nó).
    const update = (node: EditorNode) =>
        setNodes((current) => updateNode(current, node.id, node), node.id);

    /** Selecionar leva ao separador Editar. */
    const select = (id: string) => {
        setSelected(id);
        setPanel('edit');
        setMobileView('panel');
        setInsertTarget(null);
        setStructurePicker(null);
    };

    /** O que um tile da grelha cria; "columns" é um contentor com 2 colunas. */
    const newSubject = (type: string): EditorNode | null => {
        if (type === 'container') {
            return blankContainer([100]);
        }

        if (type === 'columns') {
            return blankContainer([50, 50]);
        }

        const blockSchema = schemaByType[type];

        return blockSchema
            ? blankNode(blockSchema, locales, defaultLocale)
            : null;
    };

    /** Dentro de um contentor, um bloco da loja nasce sem casca de secção. */
    const placedAt = (subject: EditorNode, target: NodeTarget): EditorNode =>
        target.parentId !== null && !isEditorContainer(subject)
            ? { ...subject, shell: false }
            : subject;

    /**
     * Da grelha: para o sítio que o "+" da página guardou; senão, um elemento
     * entra dentro do contentor selecionado e os outros casos a seguir à
     * seleção; sem seleção, no fim da página. Se não couber ali (um bloco que
     * fica sozinho), tenta a seguir à secção.
     */
    const insert = (type: string) => {
        const subject = newSubject(type);

        if (!subject) {
            return;
        }

        const found = selected ? findNode(nodes, selected) : null;
        const root = selected ? nodePath(nodes, selected)[0] : null;
        const targets: NodeTarget[] = [];

        if (insertTarget) {
            targets.push(insertTarget);
        } else {
            if (found) {
                targets.push(
                    isEditorContainer(found.node) && !isEditorContainer(subject)
                        ? {
                              parentId: found.node.id,
                              index: found.node.children.length,
                          }
                        : {
                              parentId: found.parent?.id ?? null,
                              index: found.index + 1,
                          },
                );
            }

            targets.push({
                parentId: null,
                index: root ? nodes.indexOf(root) + 1 : nodes.length,
            });
        }

        const refusals = targets.map((target) =>
            canPaste(nodes, subject, target, schemaByType, limits),
        );
        const fits = refusals.indexOf(null);

        if (fits < 0) {
            toast.error(refusals[0] ?? 'Este elemento não cabe aqui.');

            return;
        }

        const target = targets[fits];
        const placed = placedAt(subject, target);

        // O contentor à volta tem um id ao acaso: não vai dentro do updater.
        setNodes(insertNode(nodes, placed, target));
        select(placed.id);
    };

    /** Um nó novo num sítio escolhido na página; recusado, nada muda. */
    const insertAt = (subject: EditorNode, target: NodeTarget) => {
        const placed = placedAt(subject, target);
        const refused = canPaste(nodes, placed, target, schemaByType, limits);

        if (refused) {
            toast.error(refused);

            return;
        }

        setNodes(insertNode(nodes, placed, target));
        select(placed.id);
    };

    /** Da pré-visualização: largar, mudar de sítio e os botões da página. */
    const onCanvas = (message: CanvasMessage) => {
        if (message.kind === 'drop') {
            // A grelha pode já não estar lá para avisar que o arrasto acabou.
            setDraggingType(null);

            const subject = newSubject(message.type);

            if (subject) {
                insertAt(subject, message.target);
            }

            return;
        }

        if (message.kind === 'move') {
            move(message.id, message.target);

            return;
        }

        if (message.action === 'add-element' && message.target) {
            setInsertTarget(message.target);
            setStructurePicker(null);
            setPanel('elements');
            setMobileView('panel');
        }

        if (message.action === 'add-structure' && message.target) {
            if (message.columns) {
                insertAt(blankContainer(message.columns), message.target);
            } else {
                setStructurePicker({
                    target: message.target,
                    anchor: message.anchor ?? { x: 0, y: 0 },
                });
            }
        }

        if (message.id !== null && message.action === 'duplicate') {
            duplicate(message.id);
        }

        if (message.id !== null && message.action === 'remove') {
            removeIfAllowed(message.id);
        }
    };

    const move = (id: string, target: NodeTarget) => {
        const refused = canDrop(nodes, id, target, schemaByType, limits);

        if (refused) {
            toast.error(refused);

            return;
        }

        setNodes((current) => moveNode(current, id, target));
    };

    const toggle = (id: string) =>
        setNodes((current) => {
            const node = findNode(current, id)?.node;

            return node
                ? updateNode(current, id, { visible: !node.visible })
                : current;
        });

    const rename = (id: string, label: string | null) =>
        setNodes((current) => updateNode(current, id, { label }));

    const setColumns = (count: number) => {
        const found = selected ? findNode(nodes, selected) : null;

        if (!found || !isEditorContainer(found.node)) {
            return;
        }

        // As colunas novas têm ids ao acaso: não vai dentro do updater.
        const next = withColumns(found.node, count);

        if (next === found.node) {
            return;
        }

        const refused = canPaste(
            removeNode(nodes, found.node.id),
            next,
            { parentId: found.parent?.id ?? null, index: found.index },
            schemaByType,
            limits,
        );

        if (refused) {
            toast.error(refused);

            return;
        }

        setNodes(updateNode(nodes, found.node.id, next));
    };

    const duplicate = (id: string) => {
        const found = findNode(nodes, id);

        if (!found) {
            return;
        }

        const refused = canPaste(
            nodes,
            found.node,
            { parentId: found.parent?.id ?? null, index: found.index + 1 },
            schemaByType,
            limits,
        );

        if (refused) {
            toast.error(refused);

            return;
        }

        const { nodes: next, newId } = duplicateInPlace(nodes, id);
        setNodes(next);

        if (newId) {
            select(newId);
        }
    };

    const copy = (id: string) => {
        const found = findNode(nodes, id);

        if (found) {
            copyNode(found.node);
            toast(
                `“${nodeLabel(found.node, schemaByType, found.parent)}” copiado`,
            );
        }
    };

    /** Sem `id`, no fim da página (o colar noutra página, sem nada escolhido). */
    const paste = (id: string | null, where: PasteWhere) => {
        const copied = readClipboard();
        const found = id ? findNode(nodes, id) : null;

        if (!copied) {
            toast.error('Ainda não copiaste nada.');

            return;
        }

        if (id && !found) {
            return;
        }

        const target = !found
            ? { parentId: null, index: nodes.length }
            : where === 'inside' && isEditorContainer(found.node)
              ? {
                    parentId: found.node.id,
                    index: found.node.children.length,
                }
              : {
                    parentId: found.parent?.id ?? null,
                    index: found.index + 1,
                };
        const fresh = duplicateNode(copied);
        const refused = canPaste(nodes, fresh, target, schemaByType, limits);

        if (refused) {
            toast.error(refused);

            return;
        }

        setNodes(insertNode(nodes, fresh, target));
        select(fresh.id);
    };

    // Apaga logo e oferece "Desfazer" num aviso, que volta a pôr o nó onde
    // estava (mesmo que entretanto tenha havido outras alterações).
    const remove = (id: string) => {
        const found = findNode(nodes, id);
        const resetAt = resets.current;

        if (!found) {
            return;
        }

        const removed = found.node;
        const target = {
            parentId: found.parent?.id ?? null,
            index: found.index,
        };
        setNodes((current) => removeNode(current, id));

        if (selected && findNode([removed], selected)) {
            setSelected(null);
        }

        toast(`“${nodeLabel(removed, schemaByType, found.parent)}” apagado`, {
            action: {
                label: 'Desfazer',
                onClick: () =>
                    setNodes((current) =>
                        // Não volta se a página entretanto foi reposta do
                        // servidor, nem se já não cabe (outro bloco único
                        // igual, os limites, o contentor que já não existe).
                        resets.current !== resetAt ||
                        findNode(current, removed.id) ||
                        canPaste(current, removed, target, schemaByType, limits)
                            ? current
                            : insertNode(current, removed, target),
                    ),
            },
        });
    };

    /** O ✕ da página e o Ctrl+X: o que a página precisa não sai por aqui. */
    const removeIfAllowed = (id: string) => {
        const found = findNode(nodes, id);

        if (found && subtreeFlags(found.node, schemaByType).required) {
            toast.error('Esta página precisa deste bloco: não se pode apagar.');

            return;
        }

        remove(id);
    };

    // Os nomes das etiquetas e a ordem a sério dos filhos, para a página.
    const canvasTree = useMemo<CanvasTree>(() => {
        const labels: CanvasTree['labels'] = {};
        const children: CanvasTree['children'] = {
            [ROOT_KEY]: nodes.map((node) => node.id),
        };

        for (const node of walkNodes(nodes)) {
            if (isEditorContainer(node)) {
                children[node.id] = node.children.map((child) => child.id);
                node.children.forEach((child) => {
                    labels[child.id] = nodeLabel(child, schemaByType, node);
                });
            }
        }

        nodes.forEach((node) => {
            labels[node.id] = nodeLabel(node, schemaByType, null);
        });

        return { labels, children };
    }, [nodes, schemaByType]);

    // ── Ações da página ───────────────────────────────────────────────────
    const reloadFromServer = () =>
        router.reload({
            only: ['page', 'revisions'],
            onSuccess: (visit) => {
                const fresh = (visit.props as unknown as Props).page;
                resets.current += 1;
                first.current = true;
                savedVersion.current = version.current;
                setStatus(null);
                resetBlocks(upgradeRoots(fresh.blocks));
                setSeo(fresh.seo);
                setSaveState('saved');
                setErrors({});
            },
        });

    const publish = async () => {
        setPublishing(true);

        if (!(await flush())) {
            setPublishing(false);

            return;
        }

        // Os erros voltam com as posições desta árvore, não as de agora.
        const sent = latest.current.blocks;

        router.post(
            publicar(page.id).url,
            {},
            {
                preserveScroll: true,
                preserveState: true,
                // O estado do autosave já não vale, mesmo que o do servidor
                // volte a ser igual ao que se viu ao abrir a página.
                onSuccess: () => setStatus(null),
                onError: (failed) =>
                    setErrors(
                        keyErrorsByNode(sent, failed as Record<string, string>),
                    ),
                onFinish: () => setPublishing(false),
            },
        );
    };

    const action = (url: string, options: { reload?: boolean } = {}) =>
        router.post(
            url,
            {},
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    setStatus(null);

                    if (options.reload) {
                        reloadFromServer();
                    }
                },
            },
        );

    const selectedPath = selected ? nodePath(nodes, selected) : [];
    const selectedNode = selectedPath[selectedPath.length - 1] ?? null;
    const selectedParent = selectedPath[selectedPath.length - 2] ?? null;
    const selectedContainerParent =
        selectedParent && isEditorContainer(selectedParent)
            ? selectedParent
            : null;
    // Sem nada selecionado (apagado, desfeito), não há o que editar.
    const panelView = selectedNode ? panel : 'elements';

    const context: FieldContext = {
        links: props.links,
        categories: props.categories,
        tags: props.tags,
        products,
        onKnownProduct: (product) =>
            setProducts((current) => ({ ...current, [product.id]: product })),
        mediaSrc,
        onMediaSrc: (id, src) =>
            setMediaSrc((current) => ({ ...current, [id]: src })),
        errors,
    };

    const hasErrors = Object.keys(errors).length > 0;
    const saveLabel = hasErrors
        ? saveState === 'error'
            ? 'Há campos por corrigir'
            : 'Guardado — há campos por corrigir antes de publicar'
        : {
              saved: 'Rascunho guardado',
              dirty: 'Por guardar…',
              saving: 'A guardar…',
              error: 'Não foi possível guardar',
          }[saveState];

    const isLanding = page.type === 'landing';

    // ── Atalhos ───────────────────────────────────────────────────────────
    // Nunca dentro de um campo de texto (aí o desfazer é o do browser) nem
    // com um diálogo ou menu aberto.
    const onShortcut = useEffectEvent((event: KeyboardEvent) => {
        if (
            event.defaultPrevented ||
            event.isComposing ||
            navigatorDragging.current
        ) {
            return;
        }

        const target = event.target instanceof Element ? event.target : null;

        if (
            target?.closest(
                'input, textarea, select, [contenteditable]:not([contenteditable="false"]), [role="dialog"], [role="alertdialog"], [role="menu"], [role="listbox"]',
            )
        ) {
            return;
        }

        const mod = event.metaKey || event.ctrlKey;
        const key = event.key.toLowerCase();

        if (mod && !event.altKey && (key === 'z' || key === 'y')) {
            event.preventDefault();

            if (key === 'y' || event.shiftKey) {
                redo();
            } else {
                undo();
            }

            return;
        }

        if (key === 'escape' && !mod) {
            setInsertTarget(null);

            if (selectedNode) {
                setSelected(null);
                setPanel('elements');
            }

            return;
        }

        // Apagar, duplicar, copiar e colar só com o foco no Navegador (ou em
        // lado nenhum): um Backspace no botão "Publicar" não apaga o que está
        // selecionado.
        if (
            target &&
            target !== document.body &&
            !target.closest('[data-navigator]')
        ) {
            return;
        }

        // Colar não precisa de seleção: noutra página, vai para o fim. Dentro
        // só de um contentor vazio; com filhos, a seguir a ele.
        if (mod && key === 'v') {
            event.preventDefault();
            paste(
                selectedNode?.id ?? null,
                selectedNode &&
                    isEditorContainer(selectedNode) &&
                    selectedNode.children.length === 0
                    ? 'inside'
                    : 'after',
            );

            return;
        }

        if (!selectedNode) {
            return;
        }

        // Com texto escolhido, copiar é copiar esse texto.
        if (
            mod &&
            (key === 'c' || key === 'x') &&
            window.getSelection()?.isCollapsed === false
        ) {
            return;
        }

        if (mod && key === 'c') {
            event.preventDefault();
            copy(selectedNode.id);

            return;
        }

        if (mod && key === 'x') {
            event.preventDefault();
            copy(selectedNode.id);
            removeIfAllowed(selectedNode.id);

            return;
        }

        const flags = subtreeFlags(selectedNode, schemaByType);

        // Mesmo num nó que não se duplica, o Ctrl+D não vai aos favoritos.
        if (mod && key === 'd') {
            event.preventDefault();

            if (!flags.unique) {
                duplicate(selectedNode.id);
            }

            return;
        }

        if (
            !mod &&
            (key === 'delete' || key === 'backspace') &&
            !flags.required
        ) {
            event.preventDefault();
            remove(selectedNode.id);
        }
    });

    useEffect(() => {
        const listener = (event: KeyboardEvent) => onShortcut(event);
        window.addEventListener('keydown', listener);

        return () => window.removeEventListener('keydown', listener);
    }, []);

    return (
        <>
            <Head title={`Editar · ${page.name}`} />

            {/* Barra de topo: a página à esquerda, o ecrã ao centro, as
                ações à direita. Abaixo de lg o centro passa para baixo. */}
            <header className="flex shrink-0 flex-wrap items-center gap-x-3 gap-y-2 border-b border-border/60 bg-background px-2 py-2 sm:px-3 lg:grid lg:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)]">
                <div className="flex min-w-0 flex-1 items-center gap-2">
                    <Button variant="ghost" size="icon" asChild>
                        <Link
                            href={pagesIndex()}
                            aria-label="Voltar às páginas"
                        >
                            <ArrowLeft />
                        </Link>
                    </Button>
                    <div className="min-w-0">
                        <h1 className="truncate text-sm font-medium">
                            {page.name}
                        </h1>
                        <p className="truncate text-xs text-muted-foreground">
                            {page.path ??
                                (page.template === 'product'
                                    ? 'Modelo de todos os produtos'
                                    : 'Modelo do catálogo')}
                        </p>
                    </div>
                    <PageStatusBadge
                        page={{
                            ...status,
                            startsAt: page.startsAt,
                            endsAt: page.endsAt,
                        }}
                    />
                </div>

                <div className="order-last flex w-full justify-center lg:order-none lg:w-auto">
                    <PreviewControls
                        device={device}
                        onDevice={setDevice}
                        locale={locale}
                        locales={locales}
                        onLocale={setLocale}
                    />
                </div>

                <div className="flex min-w-0 items-center justify-end gap-1.5">
                    <p
                        role="status"
                        title={saveLabel}
                        className={cn(
                            'min-w-0 items-center gap-1.5 truncate text-xs sm:flex',
                            saveState === 'error' || hasErrors
                                ? 'flex text-destructive'
                                : 'hidden text-muted-foreground',
                        )}
                    >
                        {saveState === 'saving' && (
                            <Spinner className="size-3 shrink-0" />
                        )}
                        <span className="truncate">{saveLabel}</span>
                    </p>
                    <div className="flex items-center">
                        <IconAction
                            label="Desfazer"
                            hint={`${MOD} Z`}
                            disabled={!canUndo}
                            onClick={undo}
                        >
                            <Undo2 />
                        </IconAction>
                        <IconAction
                            label="Refazer"
                            hint={`${MOD} ⇧ Z`}
                            disabled={!canRedo}
                            onClick={redo}
                        >
                            <Redo2 />
                        </IconAction>
                    </div>
                    <Button
                        variant={navigatorOpen ? 'secondary' : 'outline'}
                        size="sm"
                        className="hidden lg:inline-flex"
                        aria-pressed={navigatorOpen}
                        onClick={() => saveNavigatorChoice(!navigatorOpen)}
                    >
                        <ListTree />
                        <span className="hidden xl:inline">Navegador</span>
                        <span className="sr-only xl:hidden">Navegador</span>
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => setSeoOpen(true)}
                        aria-label="SEO"
                    >
                        <Search />
                        <span className="hidden sm:inline">SEO</span>
                    </Button>
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button
                                variant="outline"
                                size="icon"
                                className="size-8"
                                aria-label="Mais ações"
                            >
                                <MoreHorizontal />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem asChild>
                                <a
                                    href={`${props.previewUrl}?locale=${locale}`}
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    <ExternalLink />
                                    Ver como cliente
                                </a>
                            </DropdownMenuItem>
                            {props.publicUrl && status.live && (
                                <DropdownMenuItem asChild>
                                    <a
                                        href={props.publicUrl}
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        <ExternalLink />
                                        Abrir no site
                                    </a>
                                </DropdownMenuItem>
                            )}
                            <DropdownMenuItem
                                onSelect={() => setHistoryOpen(true)}
                            >
                                <History />
                                Histórico
                            </DropdownMenuItem>
                            {isLanding && (
                                <DropdownMenuItem
                                    onSelect={() => setSettingsOpen(true)}
                                >
                                    <CalendarClock />
                                    Endereço e datas
                                </DropdownMenuItem>
                            )}
                            {page.template === 'page' && (
                                <DropdownMenuItem
                                    onSelect={() =>
                                        void flush().then(() =>
                                            // A cópia é outra página: começa
                                            // do zero, sem o estado (nem o
                                            // histórico) desta.
                                            router.post(
                                                duplicar(page.id).url,
                                                {},
                                                { preserveState: false },
                                            ),
                                        )
                                    }
                                >
                                    <Copy />
                                    Duplicar como landing
                                </DropdownMenuItem>
                            )}
                            <DropdownMenuSeparator />
                            {status.hasChanges && status.status !== 'draft' && (
                                <DropdownMenuItem
                                    onSelect={() =>
                                        action(descartar(page.id).url, {
                                            reload: true,
                                        })
                                    }
                                >
                                    <RotateCcw />
                                    Descartar rascunho
                                </DropdownMenuItem>
                            )}
                            {page.canUnpublish &&
                                status.status === 'published' && (
                                    <DropdownMenuItem
                                        onSelect={() =>
                                            action(despublicar(page.id).url)
                                        }
                                    >
                                        <EyeOff />
                                        Tirar do ar
                                    </DropdownMenuItem>
                                )}
                            {isLanding && (
                                <DropdownMenuItem
                                    variant="destructive"
                                    onSelect={() => setConfirmDelete(true)}
                                >
                                    <Trash2 />
                                    Apagar
                                </DropdownMenuItem>
                            )}
                        </DropdownMenuContent>
                    </DropdownMenu>
                    <Button
                        size="sm"
                        onClick={publish}
                        disabled={
                            publishing ||
                            (status.status === 'published' &&
                                !status.hasChanges &&
                                saveState === 'saved')
                        }
                    >
                        {publishing && <Spinner />}
                        Publicar
                    </Button>
                </div>
            </header>

            {/* Em ecrãs pequenos: um de cada vez */}
            <div className="shrink-0 border-b border-border/60 px-3 py-2 lg:hidden">
                <Tabs
                    value={mobileView === 'panel' ? panelView : mobileView}
                    onValueChange={(value) => {
                        if (value === 'elements' || value === 'edit') {
                            setPanel(value);
                            setMobileView('panel');
                        } else {
                            setMobileView(value as 'navigator' | 'preview');
                        }
                    }}
                >
                    <TabsList className="w-full">
                        <TabsTrigger value="elements" className="flex-1 px-1">
                            Elementos
                        </TabsTrigger>
                        <TabsTrigger
                            value="edit"
                            className="flex-1 px-1"
                            disabled={!selectedNode}
                        >
                            Editar
                        </TabsTrigger>
                        <TabsTrigger value="navigator" className="flex-1 px-1">
                            Navegador
                        </TabsTrigger>
                        <TabsTrigger value="preview" className="flex-1 px-1">
                            Página
                        </TabsTrigger>
                    </TabsList>
                </Tabs>
            </div>

            <div className="flex min-h-0 flex-1">
                <aside
                    aria-label="Elementos e edição"
                    className={cn(
                        'flex min-h-0 w-full flex-col border-border/60 bg-background lg:w-[300px] lg:shrink-0 lg:border-r',
                        mobileView !== 'panel' && 'hidden lg:flex',
                    )}
                >
                    <div className="hidden shrink-0 px-3 pt-3 pb-2 lg:block">
                        <Tabs
                            value={panelView}
                            onValueChange={(value) =>
                                setPanel(value as 'elements' | 'edit')
                            }
                        >
                            <TabsList className="w-full">
                                <TabsTrigger
                                    value="elements"
                                    className="flex-1"
                                >
                                    Elementos
                                </TabsTrigger>
                                <TabsTrigger
                                    value="edit"
                                    className="flex-1"
                                    disabled={!selectedNode}
                                >
                                    Editar
                                </TabsTrigger>
                            </TabsList>
                        </Tabs>
                    </div>
                    {errors.blocks && (
                        <p className="mx-3 mb-2 shrink-0 rounded-md bg-destructive-soft p-2 text-sm text-destructive-soft-foreground">
                            {errors.blocks}
                        </p>
                    )}
                    {panelView === 'edit' && selectedNode ? (
                        <div className="flex min-h-0 flex-1 flex-col pt-3 lg:pt-0">
                            <EditPanel
                                key={selectedNode.id}
                                node={selectedNode}
                                parent={selectedContainerParent}
                                path={errorKey(selectedNode.id)}
                                name={nodeLabel(
                                    selectedNode,
                                    schemaByType,
                                    selectedContainerParent,
                                )}
                                schema={schemaByType[selectedNode.type]}
                                container={props.container}
                                styles={props.styles}
                                locales={locales}
                                defaultLocale={defaultLocale}
                                locale={locale}
                                onLocale={setLocale}
                                device={device}
                                onChange={update}
                                onChild={update}
                                onColumns={setColumns}
                                onBack={() => setPanel('elements')}
                                context={context}
                            />
                        </div>
                    ) : (
                        <div className="flex min-h-0 flex-1 flex-col pt-3 lg:pt-0">
                            {insertTarget && (
                                <div className="mx-3 mb-2 flex shrink-0 items-center gap-2 rounded-md bg-accent p-2 text-xs text-accent-foreground">
                                    <span className="flex-1">
                                        Escolhe um elemento: vai para o sítio
                                        que marcaste na página.
                                    </span>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        className="h-6 px-1.5 text-xs"
                                        onClick={() => setInsertTarget(null)}
                                    >
                                        Cancelar
                                    </Button>
                                </div>
                            )}
                            <ElementsPanel
                                schema={schema}
                                nodes={nodes}
                                onInsert={insert}
                                onDragType={setDraggingType}
                            />
                            <div className="hidden shrink-0 border-t border-border/60 px-3 py-2 pointer-fine:block">
                                <ShortcutsHint />
                            </div>
                        </div>
                    )}
                </aside>

                {/* Pré-visualização */}
                <section
                    aria-label="Pré-visualização"
                    className={cn(
                        'min-h-0 min-w-0 flex-1',
                        mobileView !== 'preview' && 'hidden lg:block',
                    )}
                >
                    <PreviewFrame
                        url={props.previewUrl}
                        renderUrl={render(page.id).url}
                        blocks={nodes}
                        locale={locale}
                        device={device}
                        selected={selected}
                        onSelect={select}
                        tree={canvasTree}
                        dragType={draggingType}
                        onCanvas={onCanvas}
                    />
                </section>

                <aside
                    aria-label="Navegador"
                    className={cn(
                        'min-h-0 shrink-0 border-border/60 bg-background lg:w-[280px] lg:border-l',
                        mobileView === 'navigator' ? 'w-full' : 'hidden',
                        navigatorOpen ? 'lg:block' : 'lg:hidden',
                    )}
                >
                    <Navigator
                        nodes={nodes}
                        selected={selected}
                        schema={schemaByType}
                        limits={limits}
                        errors={errors}
                        onSelect={select}
                        onMove={move}
                        onToggle={toggle}
                        onRename={rename}
                        onDuplicate={duplicate}
                        onCopy={copy}
                        onPaste={paste}
                        onRemove={remove}
                        onAddStructure={(anchor) => {
                            const root = selected
                                ? nodePath(nodes, selected)[0]
                                : null;

                            setStructurePicker({
                                anchor,
                                target: {
                                    parentId: null,
                                    index: root
                                        ? nodes.indexOf(root) + 1
                                        : nodes.length,
                                },
                            });
                        }}
                        onClose={() => saveNavigatorChoice(false)}
                        onDragging={(dragging) => {
                            navigatorDragging.current = dragging;
                        }}
                    />
                </aside>
            </div>

            {structurePicker && (
                <StructurePicker
                    anchor={structurePicker.anchor}
                    onClose={closeStructurePicker}
                    onPick={(columns) => {
                        closeStructurePicker();
                        insertAt(
                            blankContainer(columns),
                            structurePicker.target,
                        );
                    }}
                />
            )}

            <SeoDialog
                open={seoOpen}
                onOpenChange={setSeoOpen}
                seo={seo}
                locales={locales}
                onChange={setSeo}
                errors={errors}
            />
            {isLanding && (
                <LandingSettingsDialog
                    open={settingsOpen}
                    onOpenChange={setSettingsOpen}
                    page={page}
                />
            )}
            <HistorySheet
                open={historyOpen}
                onOpenChange={setHistoryOpen}
                pageId={page.id}
                revisions={revisions}
                onRestored={reloadFromServer}
            />
            <ConfirmDialog
                open={confirmDelete}
                onOpenChange={setConfirmDelete}
                title="Apagar esta landing page?"
                description="Sai do site e do backoffice, com o histórico. Não se desfaz."
                confirmLabel="Apagar"
                destructive
                onConfirm={() => router.delete(destroy(page.id).url)}
            />
        </>
    );
}

function IconAction({
    label,
    hint,
    disabled,
    onClick,
    children,
}: {
    label: string;
    hint: string;
    disabled: boolean;
    onClick: () => void;
    children: ReactNode;
}) {
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                {/* O span deixa o tooltip aparecer com o botão desligado. */}
                <span>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-8"
                        aria-label={label}
                        disabled={disabled}
                        onClick={onClick}
                    >
                        {children}
                    </Button>
                </span>
            </TooltipTrigger>
            <TooltipContent>
                {label}
                <span className="ml-1.5 opacity-70">{hint}</span>
            </TooltipContent>
        </Tooltip>
    );
}
