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
    Search,
    Trash2,
    Undo2,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
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
import { PreviewFrame } from '@/components/admin/montra/preview-frame';
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
 * O editor de uma página da montra: blocos à esquerda, pré-visualização ao
 * centro, inspetor à direita. Tudo grava sozinho em rascunho; o site só muda
 * em "Publicar".
 */
export default function PageEditor(props: Props) {
    const { page, schema, locales, revisions } = props;
    const defaultLocale = locales[0];
    const wide = useIsWide();

    const [blocks, setBlocks] = useState<EditorBlock[]>(page.blocks);
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
    const first = useRef(true);
    const pending = useRef<Promise<boolean> | null>(null);
    const latest = useRef({ blocks, seo });

    useEffect(() => {
        latest.current = { blocks, seo };
    }, [blocks, seo]);

    const save = useCallback(async (): Promise<boolean> => {
        setSaveState('saving');

        try {
            const response = await requestJson<{
                page: {
                    status: EditablePage['status'];
                    hasChanges: boolean;
                    live: boolean;
                };
            }>(rascunho(page.id).url, 'PUT', latest.current);
            setStatus({
                status: response.page.status,
                hasChanges: response.page.hasChanges,
                live: response.page.live,
            });
            setErrors({});
            setSaveState('saved');

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

    useEffect(() => {
        if (first.current) {
            first.current = false;

            return;
        }

        setSaveState('dirty');
        const timer = window.setTimeout(() => {
            pending.current = save();
        }, AUTOSAVE_DELAY);

        return () => window.clearTimeout(timer);
    }, [blocks, seo, save]);

    /** Grava já o que estiver por gravar (antes de publicar ou sair). */
    const flush = useCallback(async (): Promise<boolean> => {
        if (saveState === 'dirty' || saveState === 'error') {
            return save();
        }

        if (saveState === 'saving' && pending.current) {
            return pending.current;
        }

        return true;
    }, [save, saveState]);

    useEffect(() => {
        const warn = (event: BeforeUnloadEvent) => {
            if (saveState === 'dirty' || saveState === 'saving') {
                event.preventDefault();
            }
        };

        window.addEventListener('beforeunload', warn);

        return () => window.removeEventListener('beforeunload', warn);
    }, [saveState]);

    // ── Operações nos blocos ──────────────────────────────────────────────
    const update = (block: EditorBlock) =>
        setBlocks((current) =>
            current.map((item) => (item.id === block.id ? block : item)),
        );

    const add = (blockSchema: BlockSchema) => {
        const block = blankBlock(blockSchema, locales, defaultLocale);
        setBlocks((current) => {
            const at = selected
                ? current.findIndex((item) => item.id === selected) + 1
                : current.length;
            const next = [...current];
            next.splice(at <= 0 ? current.length : at, 0, block);

            return next;
        });
        setSelected(block.id);
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

    const duplicate = (id: string) =>
        setBlocks((current) => {
            const index = current.findIndex((item) => item.id === id);
            const copy = duplicateBlock(current[index]);
            const next = [...current];
            next.splice(index + 1, 0, copy);
            setSelected(copy.id);

            return next;
        });

    const remove = (id: string) => {
        setBlocks((current) => current.filter((item) => item.id !== id));

        if (selected === id) {
            setSelected(null);
        }
    };

    // ── Ações da página ───────────────────────────────────────────────────
    const reloadFromServer = () =>
        router.reload({
            only: ['page', 'revisions'],
            onSuccess: (visit) => {
                const fresh = (visit.props as unknown as Props).page;
                first.current = true;
                setBlocks(fresh.blocks);
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

    return (
        <>
            <Head title={`Editar · ${page.name}`} />
            <div className="flex h-[calc(100svh-4rem)] min-h-0 flex-col">
                {/* Barra de topo */}
                <div className="flex flex-wrap items-center gap-2 border-b border-border/60 px-4 py-2">
                    <Button variant="ghost" size="icon" asChild>
                        <Link
                            href={pagesIndex()}
                            aria-label="Voltar às páginas"
                        >
                            <ArrowLeft />
                        </Link>
                    </Button>
                    <div className="min-w-0">
                        <h1 className="truncate font-medium">{page.name}</h1>
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
                    <span className="flex-1" />
                    <p
                        role="status"
                        className={cn(
                            'flex items-center gap-1.5 text-xs',
                            saveState === 'error' || hasErrors
                                ? 'text-destructive'
                                : 'text-muted-foreground',
                        )}
                    >
                        {saveState === 'saving' && (
                            <Spinner className="size-3" />
                        )}
                        {saveLabel}
                    </p>
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => setSeoOpen(true)}
                    >
                        <Search />
                        SEO
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
                                        router.post(duplicar(page.id).url)
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
                                    <Undo2 />
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

                {/* Em ecrãs pequenos: blocos ou pré-visualização */}
                <div className="border-b border-border/60 px-4 py-2 lg:hidden">
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

                <div className="grid min-h-0 flex-1 lg:grid-cols-[minmax(260px,320px)_1fr] xl:grid-cols-[300px_1fr_360px]">
                    {/* Lista de blocos (e, abaixo de xl, o inspetor) */}
                    <aside
                        className={cn(
                            'min-h-0 overflow-x-hidden overflow-y-auto border-border/60 p-3 lg:border-r',
                            mobileView === 'preview' && 'hidden lg:block',
                        )}
                    >
                        <div className="mb-3 flex items-center justify-between gap-2">
                            <h2 className="text-sm font-medium">
                                Blocos ({blocks.length})
                            </h2>
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => setCatalogOpen(true)}
                            >
                                <Plus />
                                Adicionar bloco
                            </Button>
                        </div>
                        {errors.blocks && (
                            <p className="mb-3 rounded-md bg-destructive-soft p-2 text-sm text-destructive-soft-foreground">
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
                        />
                        {!wide && selectedBlock && selectedSchema && (
                            <div className="mt-4 border-t border-border/60 pt-4">
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
                            </div>
                        )}
                    </aside>

                    {/* Pré-visualização */}
                    <section
                        aria-label="Pré-visualização"
                        className={cn(
                            'min-h-0',
                            mobileView === 'blocks' && 'hidden lg:block',
                        )}
                    >
                        <PreviewFrame
                            url={props.previewUrl}
                            renderUrl={render(page.id).url}
                            blocks={blocks}
                            locale={locale}
                            locales={locales}
                            onLocale={setLocale}
                            selected={selected}
                            onSelect={(id) => {
                                setSelected(id);
                                setMobileView('blocks');
                            }}
                        />
                    </section>

                    {/* Inspetor (ecrãs largos) */}
                    {wide && (
                        <aside className="min-h-0 overflow-y-auto border-l border-border/60 p-4">
                            {selectedBlock && selectedSchema ? (
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
                            ) : (
                                <div className="grid gap-2 pt-8 text-center text-sm text-muted-foreground">
                                    <p>Escolhe um bloco para o editar.</p>
                                    <p>
                                        Também podes clicar nele na
                                        pré-visualização.
                                    </p>
                                </div>
                            )}
                        </aside>
                    )}
                </div>
            </div>

            <BlockCatalog
                open={catalogOpen}
                onOpenChange={setCatalogOpen}
                schema={schema}
                blocks={blocks}
                onAdd={add}
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

PageEditor.layout = {
    breadcrumbs: [
        { title: 'Montra', href: '/admin/montra/paginas' },
        { title: 'Editor', href: '#' },
    ],
};
