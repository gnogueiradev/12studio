import { Head, router } from '@inertiajs/react';
import { Lock, MoreHorizontal, Plus, RotateCcw } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { ConfirmDialog } from '@/components/admin/confirm-dialog';
import {
    ItemEditor,
    NoItemSelected,
} from '@/components/admin/montra/menus/item-editor';
import type { ItemChange } from '@/components/admin/montra/menus/item-editor';
import {
    blankItem,
    complaintsBookItem,
    findItem,
    hasComplaintsBook,
    isComplaintsBook,
    labelOf,
    mapErrors,
    NO_ERRORS,
    removeItem,
    sameMenu,
    strip,
    updateItem,
    withUids,
} from '@/components/admin/montra/menus/menu-model';
import type {
    MenuErrors,
    MenuItem,
    MenuLimits,
    MenuLocation,
    ServerItem,
} from '@/components/admin/montra/menus/menu-model';
import { MenuPreview } from '@/components/admin/montra/menus/menu-preview';
import { MenuTree } from '@/components/admin/montra/menus/menu-tree';
import { MontraNav } from '@/components/admin/montra/montra-nav';
import { PageHeader } from '@/components/admin/page-header';
import { Panel } from '@/components/admin/panel';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Spinner } from '@/components/ui/spinner';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { reset, update } from '@/routes/admin/montra/menus';
import type { StoreLink } from '@/types/page-builder';

type Props = {
    menus: Record<MenuLocation, ServerItem[]>;
    locales: string[];
    links: StoreLink[];
    limits: Record<MenuLocation, MenuLimits>;
};

const NAMES: Record<MenuLocation, string> = {
    header: 'menu do topo',
    footer: 'rodapé',
};

/**
 * Montra → Menus. Um menu de cada vez: em cima o esboço de como fica, à
 * esquerda a estrutura (arrastar para ordenar), à direita o item escolhido.
 * Os dois menus vivem aqui, por isso trocar de separador não perde nada.
 */
export default function MenusPage({ menus, locales, links, limits }: Props) {
    const [tab, setTab] = useState<MenuLocation>('header');
    const [drafts, setDrafts] = useState<Record<MenuLocation, MenuItem[]>>(
        () => ({
            header: withUids(menus.header),
            footer: withUids(menus.footer),
        }),
    );
    const [selected, setSelected] = useState<
        Record<MenuLocation, string | null>
    >({ header: null, footer: null });
    const [errors, setErrors] = useState<Record<MenuLocation, MenuErrors>>({
        header: NO_ERRORS,
        footer: NO_ERRORS,
    });
    // O item acabado de criar: o editor abre com o cursor no texto.
    const [justAdded, setJustAdded] = useState<string | null>(null);
    const [processing, setProcessing] = useState(false);
    const [confirmReset, setConfirmReset] = useState(false);
    const [resetting, setResetting] = useState(false);
    const [confirmRemove, setConfirmRemove] = useState<string | null>(null);

    const dirty = useMemo(
        () => ({
            header: !sameMenu(drafts.header, menus.header, locales),
            footer: !sameMenu(drafts.footer, menus.footer, locales),
        }),
        [drafts, menus, locales],
    );

    // Sair com alterações por guardar (num dos dois menus) pergunta antes.
    const anyDirty = dirty.header || dirty.footer;

    useEffect(() => {
        if (!anyDirty) {
            return;
        }

        const warn = (event: BeforeUnloadEvent) => event.preventDefault();
        window.addEventListener('beforeunload', warn);
        const stop = router.on('before', (event) => {
            const visit = event.detail.visit;

            if (
                visit.method === 'get' &&
                !window.confirm(
                    'Há alterações nos menus que não guardaste. Sair na mesma?',
                )
            ) {
                event.preventDefault();
            }
        });

        return () => {
            window.removeEventListener('beforeunload', warn);
            stop();
        };
    }, [anyDirty]);

    const items = drafts[tab];
    const menuErrors = errors[tab];
    const menuLimits = limits[tab];
    const isFooter = tab === 'footer';
    const found = findItem(items, selected[tab]);
    const errorCount = Object.values(menuErrors.byUid).filter(
        (fields) => Object.keys(fields).length > 0,
    ).length;

    const setItems = (change: (items: MenuItem[]) => MenuItem[]) =>
        setDrafts((current) => ({ ...current, [tab]: change(current[tab]) }));

    const select = (uid: string | null) =>
        setSelected((current) => ({ ...current, [tab]: uid }));

    // Escolher à mão (na pré-visualização ou na estrutura).
    const pick = (uid: string) => {
        select(uid);
        setJustAdded(null);
    };

    const add = (item: MenuItem, parentUid?: string) => {
        setItems((list) =>
            parentUid
                ? list.map((parent) =>
                      parent.uid === parentUid &&
                      parent.children.length < menuLimits.children
                          ? { ...parent, children: [...parent.children, item] }
                          : parent,
                  )
                : list.length < menuLimits.items
                  ? [...list, item]
                  : list,
        );
        select(item.uid);
        setJustAdded(item.uid);
    };

    const change =
        (uid: string): ItemChange =>
        (patch, clears) => {
            setItems((list) =>
                updateItem(list, uid, (item) => ({ ...item, ...patch })),
            );
            // Mexer num campo tira-lhe o erro do último "Guardar".
            setErrors((current) => {
                const fields = current[tab].byUid[uid];

                if (!fields || !clears.some((key) => key in fields)) {
                    return current;
                }

                const rest = Object.fromEntries(
                    Object.entries(fields).filter(
                        ([key]) => !clears.includes(key),
                    ),
                );

                return {
                    ...current,
                    [tab]: {
                        ...current[tab],
                        byUid: { ...current[tab].byUid, [uid]: rest },
                    },
                };
            });
        };

    const remove = (uid: string) => {
        const target = findItem(items, uid);
        setItems((list) => removeItem(list, uid));
        // Os erros do que saiu saem com ele (senão o aviso continuava a
        // contar itens que já não estão lá).
        const gone = [uid, ...(target?.item.children ?? []).map((c) => c.uid)];
        setErrors((current) => ({
            ...current,
            [tab]: {
                ...current[tab],
                byUid: Object.fromEntries(
                    Object.entries(current[tab].byUid).filter(
                        ([key]) => !gone.includes(key),
                    ),
                ),
            },
        }));

        if (
            selected[tab] === uid ||
            target?.item.children.some((child) => child.uid === selected[tab])
        ) {
            select(target?.parent?.uid ?? null);
        }
    };

    // O Livro de Reclamações (ou uma coluna com ele) pede confirmação.
    const requestRemove = (uid: string) => {
        const target = findItem(items, uid)?.item;

        if (
            target &&
            (isComplaintsBook(target) || target.children.some(isComplaintsBook))
        ) {
            setConfirmRemove(uid);
        } else {
            remove(uid);
        }
    };

    const save = () => {
        const location = tab;
        const sent = drafts[location];
        setProcessing(true);

        router.put(
            update(location).url,
            { items: strip(sent) },
            {
                errorBag: location,
                preserveScroll: true,
                onSuccess: (page) => {
                    const fresh = (page.props as unknown as Props).menus[
                        location
                    ];
                    // O que se mexeu enquanto gravava fica (e continua por
                    // guardar); só se troca pela versão do servidor se nada
                    // mudou entretanto.
                    setDrafts((current) =>
                        current[location] === sent
                            ? {
                                  ...current,
                                  [location]: withUids(
                                      fresh,
                                      current[location],
                                  ),
                              }
                            : current,
                    );
                    setErrors((current) => ({
                        ...current,
                        [location]: NO_ERRORS,
                    }));
                },
                onError: (bag) => {
                    const mapped = mapErrors(bag, sent);
                    setErrors((current) => ({
                        ...current,
                        [location]: mapped.errors,
                    }));

                    if (mapped.first) {
                        setSelected((current) => ({
                            ...current,
                            [location]: mapped.first,
                        }));
                    }
                },
                onFinish: () => setProcessing(false),
            },
        );
    };

    const discard = () => {
        setDrafts((current) => ({ ...current, [tab]: withUids(menus[tab]) }));
        setErrors((current) => ({ ...current, [tab]: NO_ERRORS }));
        select(null);
    };

    const restoreDefaults = () => {
        const location = tab;
        setResetting(true);

        router.delete(reset(location).url, {
            preserveScroll: true,
            onSuccess: (page) => {
                setConfirmReset(false);
                setDrafts((current) => ({
                    ...current,
                    [location]: withUids(
                        (page.props as unknown as Props).menus[location],
                    ),
                }));
                setErrors((current) => ({ ...current, [location]: NO_ERRORS }));
                setSelected((current) => ({ ...current, [location]: null }));
            },
            onFinish: () => setResetting(false),
        });
    };

    // Onde o "Pôr de volta" do Livro de Reclamações o mete: a última
    // coluna com espaço.
    const complaintsColumn = [...items]
        .reverse()
        .find((column) => column.children.length < menuLimits.children);

    return (
        <>
            <Head title="Menus da montra" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Montra"
                    description="Os menus do topo e do rodapé da loja. Nada muda no site até carregares em “Guardar”."
                />
                <MontraNav current="menus" />

                <Tabs
                    value={tab}
                    onValueChange={(value) => setTab(value as MenuLocation)}
                >
                    <div className="flex items-center justify-between gap-3">
                        <TabsList>
                            {(['header', 'footer'] as const).map((location) => (
                                <TabsTrigger
                                    key={location}
                                    value={location}
                                    className="px-4"
                                >
                                    {location === 'header'
                                        ? 'Menu do topo'
                                        : 'Rodapé'}
                                    {dirty[location] && (
                                        <span className="size-1.5 rounded-full bg-warning">
                                            <span className="sr-only">
                                                (por guardar)
                                            </span>
                                        </span>
                                    )}
                                </TabsTrigger>
                            ))}
                        </TabsList>
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    aria-label="Mais opções"
                                >
                                    <MoreHorizontal />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuItem
                                    onSelect={() => setConfirmReset(true)}
                                >
                                    <RotateCcw />
                                    Repor o {NAMES[tab]} de origem
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>

                    {(['header', 'footer'] as const).map((location) => (
                        <TabsContent
                            key={location}
                            value={location}
                            className="grid gap-4"
                        >
                            <Panel
                                title="Pré-visualização"
                                aside={
                                    <span className="text-muted-foreground">
                                        Clica num item para o editar
                                    </span>
                                }
                            >
                                <MenuPreview
                                    location={location}
                                    items={items}
                                    locale={locales[0]}
                                    selected={selected[location]}
                                    errors={menuErrors}
                                    onSelect={pick}
                                />
                            </Panel>

                            <div className="grid items-start gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
                                <Panel
                                    title="Estrutura"
                                    description={
                                        isFooter
                                            ? 'Colunas com um título e os seus links.'
                                            : 'Os itens pela ordem em que aparecem. Um item com sub-links abre um submenu.'
                                    }
                                    aside={
                                        <span className="whitespace-nowrap text-muted-foreground">
                                            {items.length} de {menuLimits.items}{' '}
                                            {isFooter ? 'colunas' : 'itens'}
                                        </span>
                                    }
                                >
                                    <div className="grid gap-3">
                                        {(menuErrors.top || errorCount > 0) && (
                                            <div className="grid gap-1 rounded-md border border-destructive/40 bg-destructive-soft px-3 py-2 text-sm text-destructive-soft-foreground">
                                                {errorCount > 0 && (
                                                    <p>
                                                        {errorCount === 1
                                                            ? 'Não ficou guardado: há 1 item marcado a vermelho.'
                                                            : `Não ficou guardado: há ${errorCount} itens marcados a vermelho.`}
                                                    </p>
                                                )}
                                                <InputError
                                                    message={menuErrors.top}
                                                />
                                            </div>
                                        )}

                                        {items.length === 0 ? (
                                            <p className="rounded-md border border-dashed border-border px-3 py-6 text-center text-sm text-muted-foreground">
                                                Ainda não há{' '}
                                                {isFooter ? 'colunas' : 'itens'}
                                                .
                                            </p>
                                        ) : (
                                            <MenuTree
                                                location={location}
                                                items={items}
                                                setItems={setItems}
                                                limits={menuLimits}
                                                links={links}
                                                locales={locales}
                                                selected={selected[location]}
                                                errors={menuErrors}
                                                onSelect={pick}
                                                onAddChild={(parentUid) =>
                                                    add(blankItem(), parentUid)
                                                }
                                                onRemove={requestRemove}
                                            />
                                        )}

                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            className="w-fit"
                                            disabled={
                                                items.length >= menuLimits.items
                                            }
                                            onClick={() => add(blankItem())}
                                        >
                                            <Plus />
                                            {isFooter ? 'Coluna' : 'Item'}
                                        </Button>

                                        {isFooter &&
                                            (hasComplaintsBook(items) ? (
                                                <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                                    <Lock
                                                        aria-hidden
                                                        className="size-3.5 shrink-0"
                                                    />
                                                    O Livro de Reclamações é
                                                    obrigatório em Portugal: não
                                                    o tires.
                                                </p>
                                            ) : (
                                                <div className="grid gap-2 rounded-md border border-warning/40 bg-warning-soft px-3 py-2 text-sm text-warning-soft-foreground">
                                                    <p>
                                                        Falta o Livro de
                                                        Reclamações — é
                                                        obrigatório para quem
                                                        vende em Portugal.
                                                    </p>
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="sm"
                                                        className="w-fit"
                                                        disabled={
                                                            !complaintsColumn
                                                        }
                                                        onClick={() =>
                                                            complaintsColumn &&
                                                            add(
                                                                complaintsBookItem(
                                                                    locales,
                                                                ),
                                                                complaintsColumn.uid,
                                                            )
                                                        }
                                                    >
                                                        <Plus />
                                                        Pôr de volta
                                                    </Button>
                                                </div>
                                            ))}
                                    </div>
                                </Panel>

                                <Panel
                                    title={
                                        found
                                            ? labelOf(found.item, locales[0]) ||
                                              'Sem texto'
                                            : 'Editar'
                                    }
                                    className="lg:sticky lg:top-4"
                                >
                                    {found ? (
                                        <ItemEditor
                                            key={found.item.uid}
                                            location={location}
                                            item={found.item}
                                            parent={found.parent}
                                            locales={locales}
                                            links={links}
                                            limits={menuLimits}
                                            errors={
                                                menuErrors.byUid[
                                                    found.item.uid
                                                ] ?? {}
                                            }
                                            autoFocus={
                                                justAdded === found.item.uid
                                            }
                                            onChange={change(found.item.uid)}
                                            onRemove={() =>
                                                requestRemove(found.item.uid)
                                            }
                                            onAddChild={() =>
                                                add(blankItem(), found.item.uid)
                                            }
                                        />
                                    ) : (
                                        <NoItemSelected location={location} />
                                    )}
                                </Panel>
                            </div>
                        </TabsContent>
                    ))}
                </Tabs>

                {!dirty[tab] && anyDirty && (
                    <div className="sticky bottom-4 z-20 mt-auto flex flex-wrap items-center gap-3 rounded-xl border border-border bg-card/95 px-4 py-3 shadow-lg backdrop-blur">
                        <span
                            aria-hidden
                            className="size-2 shrink-0 rounded-full bg-warning"
                        />
                        <p className="flex-1 text-sm" role="status">
                            Tens alterações por guardar no{' '}
                            {NAMES[tab === 'header' ? 'footer' : 'header']}.
                        </p>
                        <Button
                            variant="outline"
                            onClick={() =>
                                setTab(tab === 'header' ? 'footer' : 'header')
                            }
                        >
                            Ver
                        </Button>
                    </div>
                )}
                {dirty[tab] && (
                    <div className="sticky bottom-4 z-20 mt-auto flex flex-wrap items-center gap-3 rounded-xl border border-border bg-card/95 px-4 py-3 shadow-lg backdrop-blur">
                        <span
                            aria-hidden
                            className="size-2 shrink-0 rounded-full bg-warning"
                        />
                        <p className="flex-1 text-sm" role="status">
                            Tens alterações por guardar no {NAMES[tab]}.
                        </p>
                        <Button
                            variant="ghost"
                            onClick={discard}
                            disabled={processing}
                        >
                            Descartar
                        </Button>
                        <Button onClick={save} disabled={processing}>
                            {processing && <Spinner />}
                            Guardar
                        </Button>
                    </div>
                )}
            </div>

            <ConfirmDialog
                open={confirmReset}
                onOpenChange={setConfirmReset}
                title={`Repor o ${NAMES[tab]} de origem?`}
                description="Volta aos links com que a loja nasceu. O que tens agora perde-se."
                confirmLabel="Repor"
                processing={resetting}
                onConfirm={restoreDefaults}
            />
            <ConfirmDialog
                open={confirmRemove !== null}
                onOpenChange={(open) => !open && setConfirmRemove(null)}
                title="Tirar o Livro de Reclamações?"
                description="Quem vende em Portugal é obrigado a ter o link para o Livro de Reclamações no site."
                confirmLabel="Tirar na mesma"
                destructive
                onConfirm={() => {
                    if (confirmRemove) {
                        remove(confirmRemove);
                    }

                    setConfirmRemove(null);
                }}
            />
        </>
    );
}

MenusPage.layout = {
    breadcrumbs: [{ title: 'Montra', href: '/admin/montra/paginas' }],
};
