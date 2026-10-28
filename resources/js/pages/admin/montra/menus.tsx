import {
    closestCenter,
    DndContext,
    KeyboardSensor,
    PointerSensor,
    useSensor,
    useSensors,
} from '@dnd-kit/core';
import type { DragEndEvent } from '@dnd-kit/core';
import {
    arrayMove,
    SortableContext,
    sortableKeyboardCoordinates,
    useSortable,
    verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { Head, router, usePage } from '@inertiajs/react';
import { GripVertical, Plus, RotateCcw, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import { ConfirmDialog } from '@/components/admin/confirm-dialog';
import { MontraNav } from '@/components/admin/montra/montra-nav';
import { PageHeader } from '@/components/admin/page-header';
import { Panel } from '@/components/admin/panel';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { reset, update } from '@/routes/admin/montra/menus';
import type { StoreLink } from '@/types/page-builder';

type ItemType = 'path' | 'url' | 'email';

type MenuItem = {
    /** Só no editor, para o drag and drop. */
    uid: string;
    label: Record<string, string>;
    type: ItemType;
    target: string;
    children: MenuItem[];
};

type ServerItem = Omit<MenuItem, 'uid' | 'children'> & {
    children: ServerItem[];
};

type Location = 'header' | 'footer';

type Props = {
    menus: Record<Location, ServerItem[]>;
    locales: string[];
    links: StoreLink[];
    limits: Record<Location, { items: number; children: number }>;
};

const TYPE_LABELS: Record<ItemType, string> = {
    path: 'Página da loja',
    url: 'Endereço externo',
    email: 'Email',
};

function withUids(items: ServerItem[]): MenuItem[] {
    return items.map((item) => ({
        ...item,
        uid: crypto.randomUUID(),
        children: withUids(item.children ?? []),
    }));
}

function strip(items: MenuItem[]): ServerItem[] {
    return items.map((item) => ({
        label: item.label,
        type: item.type,
        target: item.target,
        children: strip(item.children),
    }));
}

/**
 * Montra → Menus. O header tem itens com, no máximo, um nível de submenu; o
 * rodapé tem colunas, cada uma com os seus links. Arrasta-se pela pega ⠿
 * (rato ou teclado).
 */
export default function MenusPage({ menus, locales, links, limits }: Props) {
    return (
        <>
            <Head title="Menus da montra" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Montra"
                    description="Os menus do topo e do rodapé. Um rótulo vazio num idioma fica em português."
                />
                <MontraNav current="menus" />
                <div className="grid gap-4 xl:grid-cols-2">
                    <MenuEditor
                        location="header"
                        title="Menu do topo"
                        description="Até 8 itens. Um item com links por baixo vira um submenu."
                        initial={menus.header}
                        locales={locales}
                        links={links}
                        limits={limits.header}
                    />
                    <MenuEditor
                        location="footer"
                        title="Rodapé"
                        description="Até 4 colunas, cada uma com um título e os seus links. O Livro de Reclamações é obrigatório para quem vende em Portugal: não o tires."
                        initial={menus.footer}
                        locales={locales}
                        links={links}
                        limits={limits.footer}
                    />
                </div>
            </div>
        </>
    );
}

function MenuEditor({
    location,
    title,
    description,
    initial,
    locales,
    links,
    limits,
}: {
    location: Location;
    title: string;
    description: string;
    initial: ServerItem[];
    locales: string[];
    links: StoreLink[];
    limits: { items: number; children: number };
}) {
    const [items, setItems] = useState<MenuItem[]>(() => withUids(initial));
    const [processing, setProcessing] = useState(false);
    const [confirmReset, setConfirmReset] = useState(false);
    const allErrors = usePage().props.errors as unknown as Record<
        string,
        Record<string, string> | undefined
    >;
    const errors = allErrors[location] ?? {};
    const dirty = useMemo(
        () => JSON.stringify(strip(items)) !== JSON.stringify(initial),
        [items, initial],
    );
    const isFooter = location === 'footer';
    const listId = `${location}-links`;

    const save = () => {
        setProcessing(true);
        router.put(
            update(location).url,
            { items: strip(items) },
            {
                preserveScroll: true,
                errorBag: location,
                onSuccess: (page) =>
                    setItems(
                        withUids(
                            (page.props as unknown as Props).menus[location],
                        ),
                    ),
                onFinish: () => setProcessing(false),
            },
        );
    };

    const blank = (): MenuItem => ({
        uid: crypto.randomUUID(),
        label: {},
        type: 'path',
        target: '',
        children: [],
    });

    const setItem = (uid: string, next: MenuItem) =>
        setItems((current) =>
            current.map((item) => (item.uid === uid ? next : item)),
        );

    return (
        <Panel
            title={title}
            description={description}
            aside={
                <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => setConfirmReset(true)}
                >
                    <RotateCcw />
                    Repor
                </Button>
            }
        >
            <datalist id={listId}>
                {links.map((link) => (
                    <option key={link.path} value={link.path}>
                        {`${link.group}: ${link.label}`}
                    </option>
                ))}
            </datalist>
            <InputError message={errors.items} />
            <SortableItems
                items={items}
                onReorder={setItems}
                render={(item, index) => (
                    <ItemFields
                        item={item}
                        locales={locales}
                        listId={listId}
                        path={`items.${index}`}
                        errors={errors}
                        targetOptional={isFooter}
                        heading={isFooter ? `Coluna ${index + 1}` : undefined}
                        onChange={(next) => setItem(item.uid, next)}
                        onRemove={() =>
                            setItems((current) =>
                                current.filter(
                                    (other) => other.uid !== item.uid,
                                ),
                            )
                        }
                    >
                        <div className="grid gap-2 border-l-2 border-border/60 pl-3">
                            <SortableItems
                                items={item.children}
                                onReorder={(children) =>
                                    setItem(item.uid, { ...item, children })
                                }
                                render={(child, childIndex) => (
                                    <ItemFields
                                        item={child}
                                        locales={locales}
                                        listId={listId}
                                        path={`items.${index}.children.${childIndex}`}
                                        errors={errors}
                                        onChange={(next) =>
                                            setItem(item.uid, {
                                                ...item,
                                                children: item.children.map(
                                                    (other) =>
                                                        other.uid === child.uid
                                                            ? next
                                                            : other,
                                                ),
                                            })
                                        }
                                        onRemove={() =>
                                            setItem(item.uid, {
                                                ...item,
                                                children: item.children.filter(
                                                    (other) =>
                                                        other.uid !== child.uid,
                                                ),
                                            })
                                        }
                                        moveTargets={
                                            isFooter
                                                ? items
                                                      .map((column, i) => ({
                                                          uid: column.uid,
                                                          label: `Coluna ${i + 1}`,
                                                      }))
                                                      .filter(
                                                          (column) =>
                                                              column.uid !==
                                                              item.uid,
                                                      )
                                                : undefined
                                        }
                                        onMoveTo={(target) =>
                                            setItems((current) =>
                                                current.map((column) =>
                                                    column.uid === item.uid
                                                        ? {
                                                              ...column,
                                                              children:
                                                                  column.children.filter(
                                                                      (other) =>
                                                                          other.uid !==
                                                                          child.uid,
                                                                  ),
                                                          }
                                                        : column.uid === target
                                                          ? {
                                                                ...column,
                                                                children: [
                                                                    ...column.children,
                                                                    child,
                                                                ],
                                                            }
                                                          : column,
                                                ),
                                            )
                                        }
                                    />
                                )}
                            />
                            <InputError
                                message={errors[`items.${index}.children`]}
                            />
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="justify-self-start"
                                disabled={
                                    item.children.length >= limits.children
                                }
                                onClick={() =>
                                    setItem(item.uid, {
                                        ...item,
                                        children: [...item.children, blank()],
                                    })
                                }
                            >
                                <Plus />
                                {isFooter
                                    ? 'Link na coluna'
                                    : 'Link no submenu'}
                            </Button>
                        </div>
                    </ItemFields>
                )}
            />
            <div className="mt-4 flex flex-wrap items-center gap-2">
                <Button
                    type="button"
                    variant="outline"
                    disabled={items.length >= limits.items}
                    onClick={() => setItems((current) => [...current, blank()])}
                >
                    <Plus />
                    {isFooter ? 'Coluna' : 'Item'}
                </Button>
                <span className="flex-1" />
                {dirty && (
                    <span className="text-sm text-warning">
                        Alterações por guardar.
                    </span>
                )}
                <Button onClick={save} disabled={!dirty || processing}>
                    {processing && <Spinner />}
                    Guardar
                </Button>
            </div>
            <ConfirmDialog
                open={confirmReset}
                onOpenChange={setConfirmReset}
                title="Repor o menu de origem?"
                description="Volta aos links com que a loja nasceu. O menu atual perde-se."
                confirmLabel="Repor"
                onConfirm={() =>
                    router.delete(reset(location).url, {
                        preserveScroll: true,
                        onSuccess: (page) => {
                            setConfirmReset(false);
                            setItems(
                                withUids(
                                    (page.props as unknown as Props).menus[
                                        location
                                    ],
                                ),
                            );
                        },
                    })
                }
            />
        </Panel>
    );
}

function SortableItems({
    items,
    onReorder,
    render,
}: {
    items: MenuItem[];
    onReorder: (items: MenuItem[]) => void;
    render: (item: MenuItem, index: number) => React.ReactNode;
}) {
    const sensors = useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 4 } }),
        useSensor(KeyboardSensor, {
            coordinateGetter: sortableKeyboardCoordinates,
        }),
    );

    const onDragEnd = ({ active, over }: DragEndEvent) => {
        if (!over || active.id === over.id) {
            return;
        }

        const from = items.findIndex((item) => item.uid === active.id);
        const to = items.findIndex((item) => item.uid === over.id);

        if (from >= 0 && to >= 0) {
            onReorder(arrayMove(items, from, to));
        }
    };

    return (
        <DndContext
            sensors={sensors}
            collisionDetection={closestCenter}
            onDragEnd={onDragEnd}
        >
            <SortableContext
                items={items.map((item) => item.uid)}
                strategy={verticalListSortingStrategy}
            >
                <ul className="grid gap-2">
                    {items.map((item, index) => (
                        <SortableRow key={item.uid} id={item.uid}>
                            {render(item, index)}
                        </SortableRow>
                    ))}
                </ul>
            </SortableContext>
        </DndContext>
    );
}

function SortableRow({
    id,
    children,
}: {
    id: string;
    children: React.ReactNode;
}) {
    const {
        attributes,
        listeners,
        setNodeRef,
        transform,
        transition,
        isDragging,
    } = useSortable({ id });

    return (
        <li
            ref={setNodeRef}
            style={{
                transform: CSS.Transform.toString(transform),
                transition,
            }}
            className="flex gap-1 rounded-lg border border-border/60 bg-card p-2 data-[dragging=true]:z-10 data-[dragging=true]:shadow-lg"
            data-dragging={isDragging}
        >
            <button
                type="button"
                className="h-8 cursor-grab touch-none rounded px-1 text-muted-foreground hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                aria-label="Mover"
                {...attributes}
                {...listeners}
            >
                <GripVertical className="size-4" />
            </button>
            <div className="min-w-0 flex-1">{children}</div>
        </li>
    );
}

function ItemFields({
    item,
    locales,
    listId,
    path,
    errors,
    targetOptional = false,
    heading,
    onChange,
    onRemove,
    moveTargets,
    onMoveTo,
    children,
}: {
    item: MenuItem;
    locales: string[];
    listId: string;
    path: string;
    errors: Record<string, string>;
    targetOptional?: boolean;
    heading?: string;
    onChange: (item: MenuItem) => void;
    onRemove: () => void;
    moveTargets?: { uid: string; label: string }[];
    onMoveTo?: (uid: string) => void;
    children?: React.ReactNode;
}) {
    return (
        <div className="grid gap-2">
            {heading && (
                <p className="text-xs font-medium text-muted-foreground">
                    {heading}
                </p>
            )}
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                {locales.map((locale) => (
                    <div key={locale} className="grid gap-1">
                        <Label
                            htmlFor={`${item.uid}-${locale}`}
                            className="text-xs"
                        >
                            Rótulo {locale.toUpperCase()}
                        </Label>
                        <Input
                            id={`${item.uid}-${locale}`}
                            value={item.label[locale] ?? ''}
                            maxLength={40}
                            onChange={(event) =>
                                onChange({
                                    ...item,
                                    label: {
                                        ...item.label,
                                        [locale]: event.target.value,
                                    },
                                })
                            }
                        />
                        <InputError
                            message={errors[`${path}.label.${locale}`]}
                        />
                    </div>
                ))}
            </div>
            <div className="flex flex-wrap items-start gap-2">
                <Select
                    value={item.type}
                    onValueChange={(type) =>
                        onChange({ ...item, type: type as ItemType })
                    }
                >
                    <SelectTrigger
                        className="w-44"
                        aria-label="Tipo de destino"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {(Object.keys(TYPE_LABELS) as ItemType[]).map(
                            (type) => (
                                <SelectItem key={type} value={type}>
                                    {TYPE_LABELS[type]}
                                </SelectItem>
                            ),
                        )}
                    </SelectContent>
                </Select>
                <div className="grid min-w-40 flex-1 gap-1">
                    <Input
                        value={item.target}
                        list={item.type === 'path' ? listId : undefined}
                        aria-label="Destino"
                        placeholder={
                            item.type === 'path'
                                ? '/produtos'
                                : item.type === 'url'
                                  ? 'https://…'
                                  : 'ola@aminhaloja.pt'
                        }
                        onChange={(event) =>
                            onChange({ ...item, target: event.target.value })
                        }
                    />
                    {targetOptional && (
                        <p className="text-xs text-muted-foreground">
                            Opcional: o título da coluna pode não ser um link.
                        </p>
                    )}
                    <InputError message={errors[`${path}.target`]} />
                </div>
                {moveTargets && moveTargets.length > 0 && onMoveTo && (
                    <Select value="" onValueChange={onMoveTo}>
                        <SelectTrigger
                            className="w-36"
                            aria-label="Mover para outra coluna"
                        >
                            <SelectValue placeholder="Mover para…" />
                        </SelectTrigger>
                        <SelectContent>
                            {moveTargets.map((target) => (
                                <SelectItem key={target.uid} value={target.uid}>
                                    {target.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                )}
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    aria-label="Apagar"
                    onClick={onRemove}
                >
                    <Trash2 />
                </Button>
            </div>
            {children}
        </div>
    );
}

MenusPage.layout = {
    breadcrumbs: [{ title: 'Montra', href: '/admin/montra/paginas' }],
};
