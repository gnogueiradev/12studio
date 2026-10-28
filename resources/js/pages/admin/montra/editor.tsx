import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowLeft,
    CalendarClock,
    Copy,
    ExternalLink,
    EyeOff,
    History,
    MoreHorizontal,
    Plus,
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
} from 'react';
import type { ReactNode } from 'react';
import { toast } from 'sonner';
import { ConfirmDialog } from '@/components/admin/confirm-dialog';
import { BlockCatalog } from '@/components/admin/montra/block-catalog';
import { BlockList } from '@/components/admin/montra/block-list';
import { blankBlock, duplicateBlock } from '@/components/admin/montra/blocks';
import type { FieldContext } from '@/components/admin/montra/field-input';
import { Inspector } from '@/components/admin/montra/inspector';
import type { StyleOptions } from '@/components/admin/montra/inspector';
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
import { useBlockHistory } from '@/components/admin/montra/use-block-history';
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
    EditablePage,
    EditorBlock,
    ProductOption,
    Revision,
    StoreLink,
} from '@/types/page-builder';

type Props = {
    page: EditablePage;
    schema: BlockSchema[];
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

/**
 * O editor de uma página da montra, no ecrã todo: blocos à esquerda,
 * pré-visualização ao centro, inspetor à direita (só com um bloco
 * escolhido). Tudo grava sozinho em rascunho; o site só muda em "Publicar".
 */
export default function PageEditor(props: Props) {
    const { page, schema, locales, revisions } = props;
    const defaultLocale = locales[0];
    const wide = useIsWide();

    const {
        blocks,
        setBlocks,
        undo,
        redo,
        reset: resetBlocks,
        canUndo,
        canRedo,
    } = useBlockHistory(page.blocks);
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
    const [catalogOpen, setCatalogOpen] = useState(false);
    const [catalogAt, setCatalogAt] = useState<number | null>(null);
    const [device, setDevice] = useState<Device>('desktop');
    const [seoOpen, setSeoOpen] = useState(false);
    const [settingsOpen, setSettingsOpen] = useState(false);
    const [historyOpen, setHistoryOpen] = useState(false);
    const [confirmDelete, setConfirmDelete] = useState(false);
    const [mobileView, setMobileView] = useState<'blocks' | 'preview'>(
        'blocks',
    );
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
    const latest = useRef({ blocks, seo });

    useEffect(() => {
        latest.current = { blocks, seo };
    }, [blocks, seo]);

    const saveNow = useCallback(async (): Promise<boolean> => {
        const sending = version.current;
        setSaveState('saving');

        try {
            const response = await requestJson<{
                page: {
                    status: EditablePage['status'];
                    hasChanges: boolean;
                    live: boolean;
                };
            }>(rascunho(page.id).url, 'PUT', latest.current);
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
                    Object.fromEntries(
                        Object.entries(exception.body.errors ?? {}).map(
                            ([key, messages]) => [key, messages[0]],
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
    }, [blocks, seo, save]);

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

    // ── Operações nos blocos ──────────────────────────────────────────────
    // Escrever num campo junta-se num só passo do desfazer (por bloco).
    const update = (block: EditorBlock) =>
        setBlocks(
            (current) =>
                current.map((item) => (item.id === block.id ? block : item)),
            block.id,
        );

    /** `at` é a posição; sem ela, a seguir ao selecionado (ou no fim). */
    const add = (blockSchema: BlockSchema, at: number | null) => {
        const block = blankBlock(blockSchema, locales, defaultLocale);
        setBlocks((current) => {
            const after = selected
                ? current.findIndex((item) => item.id === selected) + 1
                : 0;
            const index = Math.min(
                at ?? (after > 0 ? after : current.length),
                current.length,
            );
            const next = [...current];
            next.splice(index, 0, block);

            return next;
        });
        setSelected(block.id);
    };

    const openCatalog = (at: number | null) => {
        setCatalogAt(at);
        setCatalogOpen(true);
    };

    const move = (from: number, to: number) =>
        setBlocks((current) => {
            const next = [...current];
            const [item] = next.splice(from, 1);
            next.splice(to, 0, item);

            return next;
        });

    const toggle = (id: string) =>
        setBlocks((current) =>
            current.map((item) =>
                item.id === id ? { ...item, visible: !item.visible } : item,
            ),
        );

    const duplicate = (id: string) => {
        const original = blocks.find((item) => item.id === id);

        if (!original) {
            return;
        }

        const copy = duplicateBlock(original);
        setBlocks((current) => {
            const index = current.findIndex((item) => item.id === id);
            const next = [...current];
            next.splice(index < 0 ? current.length : index + 1, 0, copy);

            return next;
        });
        setSelected(copy.id);
    };

    // Apaga logo e oferece "Desfazer" num aviso, que volta a pôr o bloco
    // onde estava (mesmo que entretanto tenha havido outras alterações).
    const remove = (id: string) => {
        const index = blocks.findIndex((item) => item.id === id);
        const resetAt = resets.current;

        if (index < 0) {
            return;
        }

        const removed = blocks[index];
        setBlocks((current) => current.filter((item) => item.id !== id));

        if (selected === id) {
            setSelected(null);
        }

        toast(
            `Bloco “${schemaByType[removed.type]?.label ?? removed.type}” apagado`,
            {
                action: {
                    label: 'Desfazer',
                    onClick: () =>
                        setBlocks((current) => {
                            // Não volta se a página entretanto foi reposta do
                            // servidor, nem se já há outro bloco único igual.
                            if (
                                resets.current !== resetAt ||
                                current.some(
                                    (item) =>
                                        item.id === removed.id ||
                                        (schemaByType[removed.type]?.unique &&
                                            item.type === removed.type),
                                )
                            ) {
                                return current;
                            }

                            const next = [...current];
                            next.splice(
                                Math.min(index, current.length),
                                0,
                                removed,
                            );

                            return next;
                        }),
                },
            },
        );
    };

    // ── Ações da página ───────────────────────────────────────────────────
    const reloadFromServer = () =>
        router.reload({
            only: ['page', 'revisions'],
            onSuccess: (visit) => {
                const fresh = (visit.props as unknown as Props).page;
                resets.current += 1;
                first.current = true;
                savedVersion.current = version.current;
                resetBlocks(fresh.blocks);
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

        router.post(
            publicar(page.id).url,
            {},
            {
                preserveScroll: true,
                preserveState: true,
                onError: (failed) =>
                    setErrors(failed as Record<string, string>),
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
                onSuccess: () => options.reload && reloadFromServer(),
            },
        );

    const errorBlocks = useMemo(() => {
        const set = new Set<number>();

        for (const key of Object.keys(errors)) {
            const match = key.match(/^blocks\.(\d+)/);

            if (match) {
                set.add(Number(match[1]));
            }
        }

        return set;
    }, [errors]);

    const selectedIndex = blocks.findIndex((block) => block.id === selected);
    const selectedBlock = selectedIndex >= 0 ? blocks[selectedIndex] : null;
    const selectedSchema = selectedBlock
        ? schemaByType[selectedBlock.type]
        : undefined;

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
    // com um diálogo ou menu aberto. Apagar e duplicar também não com o foco
    // no inspetor, para um Delete num botão de lá não levar o bloco todo.
    const onShortcut = useEffectEvent((event: KeyboardEvent) => {
        if (event.defaultPrevented || event.isComposing) {
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
        const inInspector = Boolean(target?.closest('[data-inspector]'));

        if (mod && !event.altKey && (key === 'z' || key === 'y')) {
            event.preventDefault();

            if (key === 'y' || event.shiftKey) {
                redo();
            } else {
                undo();
            }

            return;
        }

        if (!selectedBlock) {
            return;
        }

        if (key === 'escape' && !mod) {
            setSelected(null);

            return;
        }

        // Apagar e duplicar só com o foco na lista de blocos (ou em lado
        // nenhum): um Backspace no botão "Publicar" não apaga o bloco.
        if (
            inInspector ||
            (target &&
                target !== document.body &&
                !target.closest('[data-block-list]'))
        ) {
            return;
        }

        // Mesmo num bloco que não se duplica, o Ctrl+D não vai aos favoritos.
        if (mod && key === 'd') {
            event.preventDefault();

            if (!selectedSchema?.unique) {
                duplicate(selectedBlock.id);
            }

            return;
        }

        if (
            !mod &&
            (key === 'delete' || key === 'backspace') &&
            !selectedSchema?.required
        ) {
            event.preventDefault();
            remove(selectedBlock.id);
        }
    });

    useEffect(() => {
        const listener = (event: KeyboardEvent) => onShortcut(event);
        window.addEventListener('keydown', listener);

        return () => window.removeEventListener('keydown', listener);
    }, []);

    const inspector = selectedBlock && selectedSchema && (
        <Inspector
            key={selectedBlock.id}
            block={selectedBlock}
            index={selectedIndex}
            schema={selectedSchema}
            styles={props.styles}
            locales={locales}
            defaultLocale={defaultLocale}
            locale={locale}
            onLocale={setLocale}
            onChange={update}
            onClose={() => setSelected(null)}
            context={context}
        />
    );

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

            {/* Em ecrãs pequenos: blocos ou pré-visualização */}
            <div className="shrink-0 border-b border-border/60 px-3 py-2 lg:hidden">
                <Tabs
                    value={mobileView}
                    onValueChange={(value) =>
                        setMobileView(value as 'blocks' | 'preview')
                    }
                >
                    <TabsList className="w-full">
                        <TabsTrigger value="blocks" className="flex-1">
                            Blocos
                        </TabsTrigger>
                        <TabsTrigger value="preview" className="flex-1">
                            Pré-visualização
                        </TabsTrigger>
                    </TabsList>
                </Tabs>
            </div>

            <div className="flex min-h-0 flex-1">
                {/* Lista de blocos (e, abaixo de xl, o inspetor no lugar
                    dela enquanto houver um bloco escolhido) */}
                <aside
                    aria-label="Blocos"
                    className={cn(
                        'flex min-h-0 w-full flex-col border-border/60 bg-background lg:w-[300px] lg:shrink-0 lg:border-r',
                        mobileView === 'preview' && 'hidden lg:flex',
                    )}
                >
                    {!wide && inspector ? (
                        <div
                            data-inspector
                            className="min-h-0 flex-1 overflow-x-hidden overflow-y-auto p-3"
                        >
                            <Button
                                variant="ghost"
                                size="sm"
                                className="mb-2 -ml-2 text-muted-foreground"
                                onClick={() => setSelected(null)}
                            >
                                <ArrowLeft />
                                Todos os blocos
                            </Button>
                            {inspector}
                        </div>
                    ) : (
                        <>
                            <div className="flex shrink-0 items-baseline justify-between gap-2 px-3 pt-3 pb-1">
                                <h2 className="text-sm font-medium">Blocos</h2>
                                <span className="text-xs text-muted-foreground tabular-nums">
                                    {blocks.length}
                                </span>
                            </div>
                            <div className="min-h-0 flex-1 overflow-x-hidden overflow-y-auto px-3 pb-3">
                                {errors.blocks && (
                                    <p className="mb-2 rounded-md bg-destructive-soft p-2 text-sm text-destructive-soft-foreground">
                                        {errors.blocks}
                                    </p>
                                )}
                                <BlockList
                                    blocks={blocks}
                                    schema={schemaByType}
                                    selected={selected}
                                    locales={locales}
                                    defaultLocale={defaultLocale}
                                    errorBlocks={errorBlocks}
                                    onSelect={setSelected}
                                    onMove={move}
                                    onToggle={toggle}
                                    onDuplicate={duplicate}
                                    onRemove={remove}
                                    onInsert={openCatalog}
                                />
                            </div>
                            <div className="grid shrink-0 gap-1 border-t border-border/60 p-3">
                                <Button
                                    variant="outline"
                                    className="w-full border-dashed"
                                    onClick={() => openCatalog(blocks.length)}
                                >
                                    <Plus />
                                    Adicionar bloco
                                </Button>
                                <div className="hidden pointer-fine:block">
                                    <ShortcutsHint />
                                </div>
                            </div>
                        </>
                    )}
                </aside>

                {/* Pré-visualização */}
                <section
                    aria-label="Pré-visualização"
                    className={cn(
                        'min-h-0 min-w-0 flex-1',
                        mobileView === 'blocks' && 'hidden lg:block',
                    )}
                >
                    <PreviewFrame
                        url={props.previewUrl}
                        renderUrl={render(page.id).url}
                        blocks={blocks}
                        locale={locale}
                        device={device}
                        selected={selected}
                        onSelect={(id) => {
                            setSelected(id);
                            setMobileView('blocks');
                        }}
                    />
                </section>

                {/* Inspetor (ecrãs largos): só ocupa espaço com um bloco
                    escolhido; a pré-visualização ajusta o zoom. */}
                {wide && (
                    <aside
                        aria-label="Inspetor"
                        className={cn(
                            'min-h-0 shrink-0 overflow-hidden border-border/60 bg-background transition-[width] duration-200 ease-out',
                            inspector ? 'w-[360px] border-l' : 'w-0',
                        )}
                    >
                        {inspector && (
                            <div
                                data-inspector
                                className="h-full w-[360px] overflow-y-auto p-4"
                            >
                                {inspector}
                            </div>
                        )}
                    </aside>
                )}
            </div>

            <BlockCatalog
                open={catalogOpen}
                onOpenChange={setCatalogOpen}
                schema={schema}
                blocks={blocks}
                at={catalogAt}
                onAdd={(blockSchema) => add(blockSchema, catalogAt)}
            />
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
