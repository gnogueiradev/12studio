/**
 * Tipos do construtor de páginas (backoffice). Espelham o esquema que o
 * App\PageBuilder\Block::schema() manda e o JSON que o BlockSanitizer grava.
 */

import type { StoreDeviceValues, StoreNode } from './store';

export type FieldType =
    | 'text'
    | 'textarea'
    | 'richtext'
    | 'image'
    | 'link'
    | 'select'
    | 'toggle'
    | 'number'
    | 'products'
    | 'categories'
    | 'tags'
    | 'icon'
    | 'repeater';

export type FieldSchema = {
    key: string;
    type: FieldType;
    label: string;
    translatable: boolean;
    required: boolean;
    max?: number;
    min?: number;
    options?: { value: string; label: string }[];
    fields?: FieldSchema[];
    default?: unknown;
    help?: string;
    icons?: string[];
};

export type BlockSchema = {
    type: string;
    label: string;
    description: string;
    variants: { value: string; label: string }[];
    templates: string[];
    required: boolean;
    unique: boolean;
    defaultStyle: EditorBlockStyle;
    fields: FieldSchema[];
    styleFields: StylePropSchema[];
    advancedFields: StylePropSchema[];
    hasShell: boolean;
    rootOnly: boolean;
};

/** App\PageBuilder\StyleProp::toArray() */
export type StylePropSchema = {
    key: string;
    type: 'enum' | 'int' | 'token' | 'toggle' | 'media';
    label: string;
    min?: number;
    max?: number;
    unit?: string;
    default?: string | number;
    options?: { value: string; label: string }[];
};

/** App\PageBuilder\Container::schema() */
export type ContainerSchema = {
    styleProps: StylePropSchema[];
    advancedFields: StylePropSchema[];
    limits: {
        maxDepth: number;
        maxNodes: number;
        maxRowChildren: number;
    };
};

/** Valores por dispositivo, por propriedade; o que falta herda de cima. */
export type EditorDeviceProps = Record<string, StoreDeviceValues>;

export type EditorBlockStyle = {
    bg: string;
    spacing: string;
    width: string;
    align: string;
};

/** Um elemento como fica gravado (draft_blocks). */
export type EditorBlock = {
    id: string;
    type: string;
    v: number;
    visible: boolean;
    label: string | null;
    variant: string | null;
    style: EditorBlockStyle;
    shell: boolean;
    styleProps: EditorDeviceProps;
    advanced: EditorDeviceProps;
    settings: Record<string, unknown>;
    content: Record<string, Record<string, unknown>>;
};

/** Um contentor como fica gravado: o único nó com filhos. */
export type EditorContainer = {
    id: string;
    type: 'container';
    v: number;
    visible: boolean;
    label: string | null;
    settings: Record<string, unknown>;
    content: Record<string, Record<string, unknown>>;
    style: EditorDeviceProps;
    advanced: EditorDeviceProps;
    children: EditorNode[];
};

export type EditorNode = EditorContainer | EditorBlock;

export type LinkValue = { label: string; href: string } | null;

export type PageStatus = 'draft' | 'published' | 'unpublished';

export type PageRow = {
    id: number;
    key: string | null;
    type: 'system' | 'landing';
    name: string;
    slug: string | null;
    path: string | null;
    template: 'page' | 'catalog' | 'product';
    status: PageStatus;
    hasChanges: boolean;
    live: boolean;
    startsAt: string | null;
    endsAt: string | null;
    publishedAt: string | null;
    updatedAt: string | null;
    canUnpublish: boolean;
};

export type EditablePage = PageRow & {
    blocks: EditorNode[];
    seo: Record<string, { title?: string; description?: string }>;
    startsAtInput: string | null;
    endsAtInput: string | null;
};

export type Revision = {
    id: number;
    createdAt: string | null;
    user: string | null;
    blocks: number;
};

export type StoreLink = { label: string; path: string; group: string };

export type ProductOption = {
    id: number;
    name: string;
    status: string;
    image: string | null;
    href: string;
};

export type MediaItem = {
    id: number;
    name: string | null;
    alt: string | null;
    width: number | null;
    height: number | null;
    size: number | null;
    image: {
        src: string;
        srcset: string | null;
        width: number | null;
        height: number | null;
        alt: string;
    };
};

/** Mensagens entre o editor e o iframe da pré-visualização. */
export type PreviewMessage =
    /** A árvore já renderizada pelo servidor (PageRenderer). */
    | { source: 'studio-editor'; kind: 'blocks'; blocks: StoreNode[] }
    | { source: 'studio-editor'; kind: 'select'; id: string | null }
    | {
          source: 'studio-editor';
          kind: 'theme';
          css: string;
          fonts: string;
          mode: 'light' | 'dark';
      }
    | { source: 'studio-preview'; kind: 'ready' }
    | { source: 'studio-preview'; kind: 'select'; id: string };
