import { ArrowLeft, Languages } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import {
    columnCount,
    isEditorContainer,
    stacksOnMobile,
} from '@/lib/page-tree';
import type {
    BlockSchema,
    ContainerSchema,
    EditorBlock,
    EditorBlockStyle,
    EditorContainer,
    EditorNode,
    StylePropSchema,
} from '@/types/page-builder';
import type { StoreDeviceValues } from '@/types/store';
import { BlockIcon, isEmpty, missingTranslations } from '../blocks';
import { FieldInput } from '../field-input';
import type { FieldContext } from '../field-input';
import type { Device } from '../preview-frame';
import { withProp } from './device-values';
import { StyleControl, Switch } from './style-control';

export type StyleOptions = Record<
    keyof EditorBlockStyle,
    { value: string; label: string }[]
>;

const CLASSIC_LABELS: Record<keyof EditorBlockStyle, string> = {
    bg: 'Fundo',
    spacing: 'Espaçamento',
    width: 'Largura',
    align: 'Alinhamento',
};

const LOCALE_NAMES: Record<string, string> = {
    pt: 'PT',
    es: 'ES',
    en: 'EN',
    fr: 'FR',
};

/** As propriedades do contentor, pela ordem de quem as procura. */
const CONTAINER_GROUPS: { name: string; keys: string[] }[] = [
    {
        name: 'Disposição',
        keys: ['direction', 'wrap', 'gap', 'justify', 'align'],
    },
    { name: 'Tamanho', keys: ['width', 'max_width', 'min_height'] },
    { name: 'Espaço', keys: ['padding_y', 'padding_x'] },
    { name: 'Fundo', keys: ['bg', 'bg_image', 'overlay'] },
    { name: 'Aspeto', keys: ['radius', 'text_align'] },
];

/** A montra só lê estes do computador: não variam por dispositivo. */
const SINGLE_VALUE = new Set([
    'style.bg',
    'style.bg_image',
    'styleProps.level',
]);

const STACK_HINT = 'empilha no telemóvel';

type Tab = 'content' | 'style' | 'advanced';

const TAB_PREFIXES: Record<Tab, string[]> = {
    content: ['content', 'settings'],
    style: ['style', 'styleProps'],
    advanced: ['advanced'],
};

type Props = {
    node: EditorNode;
    /** O pai, para o que muda dentro de uma linha empilhada. */
    parent: EditorContainer | null;
    /** A chave dos erros deste nó (`errorKey`). */
    path: string;
    name: string;
    schema: BlockSchema | undefined;
    container: ContainerSchema;
    styles: StyleOptions;
    locales: string[];
    defaultLocale: string;
    locale: string;
    onLocale: (locale: string) => void;
    device: Device;
    onChange: (node: EditorNode) => void;
    onChild: (child: EditorContainer) => void;
    onColumns: (count: number) => void;
    onBack: () => void;
    context: FieldContext;
};

/** O separador Editar do painel esquerdo. */
export function EditPanel(props: Props) {
    const { node, path, name, schema, context, onBack } = props;
    const isGroup = isEditorContainer(node);
    const hasContent = !isGroup && (schema?.fields.length ?? 0) > 0;
    const [tab, setTab] = useState<Tab>(hasContent ? 'content' : 'style');

    const keys = Object.keys(context.errors).filter(
        (key) => key === path || key.startsWith(`${path}.`),
    );
    const inSection = (key: string, section: Tab) =>
        TAB_PREFIXES[section].some((prefix) =>
            key.startsWith(`${path}.${prefix}.`),
        );
    const failing = (section: Tab) =>
        keys.some((key) => inSection(key, section));
    // O que não pertence a nenhum campo: o próprio nó, o nome, os filhos.
    const loose = keys.filter(
        (key) =>
            !(['content', 'style', 'advanced'] as Tab[]).some((section) =>
                inSection(key, section),
            ),
    );

    const tabs: { value: Tab; label: string }[] = [
        ...(hasContent
            ? [{ value: 'content' as const, label: 'Conteúdo' }]
            : []),
        { value: 'style', label: 'Estilo' },
        { value: 'advanced', label: 'Avançado' },
    ];

    return (
        <div className="flex min-h-0 flex-1 flex-col">
            <div className="grid shrink-0 gap-2 px-3 pb-2">
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="-ml-2 w-fit text-muted-foreground"
                    onClick={onBack}
                >
                    <ArrowLeft />
                    Elementos
                </Button>
                <div className="flex min-w-0 items-center gap-2">
                    <BlockIcon
                        type={node.type}
                        className="size-4 shrink-0 text-muted-foreground"
                    />
                    <h2 className="truncate text-sm font-medium">{name}</h2>
                </div>
                {schema?.description && (
                    <p className="text-xs text-muted-foreground">
                        {schema.description}
                    </p>
                )}
                <Tabs
                    value={tab}
                    onValueChange={(value) => setTab(value as Tab)}
                >
                    <TabsList className="w-full">
                        {tabs.map((item) => (
                            <TabsTrigger
                                key={item.value}
                                value={item.value}
                                className="flex-1"
                            >
                                {item.label}
                                {failing(item.value) && (
                                    <>
                                        <span
                                            aria-hidden
                                            className="size-1.5 rounded-full bg-destructive"
                                        />
                                        <span className="sr-only">
                                            (tem erros)
                                        </span>
                                    </>
                                )}
                            </TabsTrigger>
                        ))}
                    </TabsList>
                </Tabs>
            </div>
            <div className="min-h-0 flex-1 overflow-x-hidden overflow-y-auto px-3 pb-4">
                {loose.map((key) => (
                    <p
                        key={key}
                        className="mb-3 rounded-md bg-destructive-soft p-2 text-sm text-destructive-soft-foreground"
                    >
                        {context.errors[key]}
                    </p>
                ))}
                {tab === 'content' && !isGroup && schema && (
                    <ContentTab {...props} block={node} schema={schema} />
                )}
                {tab === 'style' &&
                    (isGroup ? (
                        <ContainerStyle {...props} group={node} />
                    ) : (
                        <ElementStyle {...props} block={node} />
                    ))}
                {tab === 'advanced' && <AdvancedTab {...props} />}
            </div>
        </div>
    );
}

function ContentTab({
    block,
    schema,
    path,
    locales,
    defaultLocale,
    locale,
    onLocale,
    onChange,
    context,
}: Props & { block: EditorBlock; schema: BlockSchema }) {
    const translatable = schema.fields.filter((field) => field.translatable);
    const settings = schema.fields.filter((field) => !field.translatable);
    const missing = missingTranslations(block, schema, locales, defaultLocale);
    const content = block.content[locale] ?? {};
    const base = block.content[defaultLocale] ?? {};

    const setContent = (key: string, value: unknown) =>
        onChange({
            ...block,
            content: {
                ...block.content,
                [locale]: { ...(block.content[locale] ?? {}), [key]: value },
            },
        });

    const copyFromDefault = () =>
        onChange({
            ...block,
            content: {
                ...block.content,
                [locale]: {
                    ...structuredClone(base),
                    ...Object.fromEntries(
                        Object.entries(content).filter(
                            ([, value]) => !isEmpty(value),
                        ),
                    ),
                },
            },
        });

    return (
        <div className="grid gap-5">
            {translatable.length > 0 && (
                <section className="grid gap-3" aria-label="Conteúdo">
                    {locales.length > 1 && (
                        <Tabs value={locale} onValueChange={onLocale}>
                            <TabsList>
                                {locales.map((code) => (
                                    <TabsTrigger key={code} value={code}>
                                        {LOCALE_NAMES[code] ?? code}
                                        {missing.includes(code) && (
                                            <>
                                                <span
                                                    aria-hidden
                                                    className="size-1.5 rounded-full bg-warning"
                                                />
                                                <span className="sr-only">
                                                    (falta tradução)
                                                </span>
                                            </>
                                        )}
                                    </TabsTrigger>
                                ))}
                            </TabsList>
                        </Tabs>
                    )}
                    {locale !== defaultLocale && (
                        <div className="flex flex-wrap items-center gap-2 rounded-md bg-muted/60 p-2 text-xs text-muted-foreground">
                            <Languages aria-hidden className="size-4" />
                            <span className="flex-1">
                                O que ficar vazio aparece em português.
                            </span>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={copyFromDefault}
                            >
                                Copiar do português
                            </Button>
                        </div>
                    )}
                    {translatable.map((field) => (
                        <FieldInput
                            key={`${locale}-${field.key}`}
                            field={field}
                            value={content[field.key]}
                            onChange={(value) => setContent(field.key, value)}
                            idPrefix={`${block.id}-${locale}`}
                            path={`${path}.content.${locale}.${field.key}`}
                            context={context}
                        />
                    ))}
                </section>
            )}

            {settings.length > 0 && (
                <section className="grid gap-3" aria-label="Opções">
                    <h3 className="text-sm font-medium">Opções</h3>
                    {settings.map((field) => (
                        <FieldInput
                            key={field.key}
                            field={field}
                            value={block.settings[field.key]}
                            onChange={(value) =>
                                onChange({
                                    ...block,
                                    settings: {
                                        ...block.settings,
                                        [field.key]: value,
                                    },
                                })
                            }
                            idPrefix={`${block.id}-settings`}
                            path={`${path}.settings.${field.key}`}
                            context={context}
                        />
                    ))}
                </section>
            )}
        </div>
    );
}

/** Uma lista de propriedades por dispositivo, no sítio `field` do nó. */
function PropList({
    node,
    props: list,
    values,
    field,
    path,
    device,
    context,
    onValues,
    unset,
}: {
    node: EditorNode;
    props: StylePropSchema[];
    values: Record<string, StoreDeviceValues>;
    field: string;
    path: string;
    device: Device;
    context: FieldContext;
    onValues: (key: string, values: StoreDeviceValues) => void;
    unset?: (key: string) => { value: unknown; hint: string } | undefined;
}) {
    return (
        <>
            {list.map((prop) => (
                <StyleControl
                    key={prop.key}
                    prop={prop}
                    values={values[prop.key]}
                    device={device}
                    onChange={(next) => onValues(prop.key, next)}
                    idPrefix={`${node.id}-${field}`}
                    path={`${path}.${field}.${prop.key}`}
                    context={context}
                    single={SINGLE_VALUE.has(`${field}.${prop.key}`)}
                    unset={unset?.(prop.key)}
                />
            ))}
        </>
    );
}

function ContainerStyle({
    group,
    parent,
    path,
    container,
    device,
    onChange,
    onChild,
    onColumns,
    context,
}: Props & { group: EditorContainer }) {
    const props = container.styleProps;
    // Sem valor, o contentor é neutro: o "Contido" do esquema só vale ao criar.
    const shown = (prop: StylePropSchema): StylePropSchema =>
        prop.key === 'max_width' ? { ...prop, default: 'full' } : prop;
    const widthProp = props.find((prop) => prop.key === 'width');
    const columns = columnCount(group);
    const isRow = group.style.direction?.desktop === 'row';
    const columnChildren = isRow
        ? group.children.filter(isEditorContainer)
        : [];

    // No telemóvel, sem valor próprio, a montra não herda: uma linha empilha.
    const unset = (key: string) => {
        if (device !== 'mobile') {
            return undefined;
        }

        if (key === 'direction' && stacksOnMobile(group)) {
            return { value: 'column', hint: STACK_HINT };
        }

        return key === 'width' && parent && stacksOnMobile(parent)
            ? { value: 100, hint: STACK_HINT }
            : undefined;
    };

    return (
        <div className="grid gap-5">
            <section className="grid gap-2" aria-label="Colunas">
                <Label>Colunas</Label>
                <ToggleGroup
                    type="single"
                    size="sm"
                    variant="outline"
                    value={String(columns)}
                    onValueChange={(value) => {
                        if (value) {
                            onColumns(Number(value));
                        }
                    }}
                    aria-label="Número de colunas"
                >
                    {[1, 2, 3, 4].map((count) => (
                        <ToggleGroupItem
                            key={count}
                            value={String(count)}
                            className="w-9"
                        >
                            {count}
                        </ToggleGroupItem>
                    ))}
                </ToggleGroup>
                {widthProp &&
                    columnChildren.map((child, index) => (
                        <StyleControl
                            key={child.id}
                            prop={{
                                ...widthProp,
                                label: child.label || `Coluna ${index + 1}`,
                            }}
                            values={child.style.width}
                            device={device}
                            onChange={(next) =>
                                onChild({
                                    ...child,
                                    style: withProp(child.style, 'width', next),
                                })
                            }
                            idPrefix={`${child.id}-column`}
                            path={`node:${child.id}.style.width`}
                            context={context}
                            unset={
                                device === 'mobile' && stacksOnMobile(group)
                                    ? { value: 100, hint: STACK_HINT }
                                    : undefined
                            }
                        />
                    ))}
            </section>

            {CONTAINER_GROUPS.map((section) => {
                const list = section.keys.flatMap((key) => {
                    const prop = props.find((item) => item.key === key);

                    // Escurecer só faz sentido com uma imagem de fundo.
                    return prop &&
                        (key !== 'overlay' || group.style.bg_image?.desktop)
                        ? [shown(prop)]
                        : [];
                });

                return (
                    <section
                        key={section.name}
                        className="grid gap-3"
                        aria-label={section.name}
                    >
                        <h3 className="text-sm font-medium">{section.name}</h3>
                        <PropList
                            node={group}
                            props={list}
                            values={group.style}
                            field="style"
                            path={path}
                            device={device}
                            context={context}
                            unset={unset}
                            onValues={(key, next) => {
                                // O servidor recusava-o ao gravar.
                                if (
                                    key === 'direction' &&
                                    next.desktop === 'row' &&
                                    group.children.length >
                                        container.limits.maxRowChildren
                                ) {
                                    toast.error(
                                        `Um contentor em linha leva no máximo ${container.limits.maxRowChildren} elementos lado a lado.`,
                                    );

                                    return;
                                }

                                onChange({
                                    ...group,
                                    style: withProp(group.style, key, next),
                                });
                            }}
                        />
                    </section>
                );
            })}
        </div>
    );
}

function ElementStyle({
    block,
    schema,
    styles,
    path,
    device,
    onChange,
    context,
}: Props & { block: EditorBlock }) {
    const hasShell = schema?.hasShell ?? false;
    const fields = schema?.styleFields ?? [];
    const variants = schema?.variants ?? [];
    const classic = (
        block.shell
            ? Object.keys(CLASSIC_LABELS)
            : (['align'] as (keyof EditorBlockStyle)[])
    ) as (keyof EditorBlockStyle)[];

    return (
        <div className="grid gap-5">
            {hasShell && (
                <section className="grid gap-3" aria-label="Casca de secção">
                    <div className="flex items-center gap-2">
                        <Label
                            htmlFor={`${block.id}-shell`}
                            className="flex-1 font-normal"
                        >
                            Casca de secção
                        </Label>
                        <Switch
                            id={`${block.id}-shell`}
                            checked={block.shell}
                            onChange={(shell) => onChange({ ...block, shell })}
                        />
                    </div>
                    <p className="text-xs text-muted-foreground">
                        {block.shell
                            ? 'O elemento traz o seu fundo, largura e espaçamento.'
                            : 'O fundo e o espaço passam a ser os do contentor.'}
                    </p>
                    {block.shell && variants.length > 1 && (
                        <div className="grid gap-1.5">
                            <Label htmlFor={`${block.id}-variant`}>
                                Variante
                            </Label>
                            <Select
                                value={block.variant ?? variants[0].value}
                                onValueChange={(variant) =>
                                    onChange({ ...block, variant })
                                }
                            >
                                <SelectTrigger id={`${block.id}-variant`}>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {variants.map((variant) => (
                                        <SelectItem
                                            key={variant.value}
                                            value={variant.value}
                                        >
                                            {variant.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    )}
                    <div className="grid grid-cols-2 gap-3">
                        {classic.map((key) => (
                            <div key={key} className="grid gap-1.5">
                                <Label htmlFor={`${block.id}-style-${key}`}>
                                    {CLASSIC_LABELS[key]}
                                </Label>
                                <Select
                                    value={block.style[key]}
                                    onValueChange={(value) =>
                                        onChange({
                                            ...block,
                                            style: {
                                                ...block.style,
                                                [key]: value,
                                            },
                                        })
                                    }
                                >
                                    <SelectTrigger
                                        id={`${block.id}-style-${key}`}
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {styles[key].map((option) => (
                                            <SelectItem
                                                key={option.value}
                                                value={option.value}
                                            >
                                                {option.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError
                                    message={
                                        context.errors[`${path}.style.${key}`]
                                    }
                                />
                            </div>
                        ))}
                    </div>
                </section>
            )}

            {fields.length > 0 && (
                <section className="grid gap-3" aria-label="Estilo">
                    <PropList
                        node={block}
                        props={fields}
                        values={block.styleProps}
                        field="styleProps"
                        path={path}
                        device={device}
                        context={context}
                        onValues={(key, next) =>
                            onChange({
                                ...block,
                                styleProps: withProp(
                                    block.styleProps,
                                    key,
                                    next,
                                ),
                            })
                        }
                    />
                </section>
            )}

            {!hasShell && fields.length === 0 && (
                <p className="text-sm text-muted-foreground">
                    Este elemento não tem opções de estilo.
                </p>
            )}
        </div>
    );
}

function AdvancedTab({
    node,
    schema,
    container,
    path,
    device,
    onChange,
    context,
}: Props) {
    const list = isEditorContainer(node)
        ? container.advancedFields
        : (schema?.advancedFields ?? []);

    return (
        <section className="grid gap-3" aria-label="Avançado">
            <PropList
                node={node}
                props={list}
                values={node.advanced}
                field="advanced"
                path={path}
                device={device}
                context={context}
                onValues={(key, next) =>
                    onChange({
                        ...node,
                        advanced: withProp(node.advanced, key, next),
                    } as EditorNode)
                }
            />
        </section>
    );
}
