import {
    Columns2,
    Columns3,
    FileText,
    GalleryHorizontal,
    Grid3x3,
    Heading,
    HelpCircle,
    Image as ImageIcon,
    LayoutGrid,
    List,
    ListOrdered,
    Mail,
    Megaphone,
    MessageSquareQuote,
    Minus,
    PencilRuler,
    Pilcrow,
    Play,
    Quote,
    RectangleHorizontal,
    Rows3,
    ShieldCheck,
    Square,
    Star,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { createElement } from 'react';
import { columnCount, isEditorContainer, walkNodes } from '@/lib/page-tree';
import { randomId } from '@/lib/utils';
import type {
    BlockSchema,
    EditorBlock,
    EditorContainer,
    EditorNode,
    FieldSchema,
} from '@/types/page-builder';

// Vivem em page-tree.ts, que a montra também importa (sem os ícones de cá).
export { blankContainer, duplicateNode } from '@/lib/page-tree';

/** Um ícone por tipo, para o catálogo e a lista se lerem de relance. */
const ICONS: Record<string, LucideIcon> = {
    hero: Megaphone,
    product_grid: LayoutGrid,
    categories: Grid3x3,
    text: FileText,
    text_image: Columns2,
    banner: Rows3,
    gallery: GalleryHorizontal,
    steps: ListOrdered,
    faq: HelpCircle,
    testimonials: MessageSquareQuote,
    trust: ShieldCheck,
    contact: Mail,
    custom_order: PencilRuler,
    spacer: Square,
    heading: Heading,
    paragraph: Pilcrow,
    image: ImageIcon,
    button: RectangleHorizontal,
    icon: Star,
    divider: Minus,
    video: Play,
    icon_list: List,
    quote: Quote,
    container: Square,
    columns: Columns3,
};

/** O ícone do tipo de bloco (decorativo: o nome vai sempre ao lado). */
export function BlockIcon({
    type,
    className,
}: {
    type: string;
    className?: string;
}) {
    return createElement(ICONS[type] ?? ImageIcon, {
        'aria-hidden': true,
        className,
    });
}

/** O valor de um campo vazio — o mesmo do BlockSanitizer::emptyValue(). */
export function emptyValue(field: FieldSchema): unknown {
    switch (field.type) {
        case 'text':
        case 'textarea':
        case 'richtext':
            return '';
        case 'image':
        case 'link':
            return null;
        case 'products':
        case 'categories':
        case 'tags':
        case 'repeater':
            return [];
        default:
            return field.default ?? null;
    }
}

export function isEmpty(value: unknown): boolean {
    return (
        value === null ||
        value === undefined ||
        value === '' ||
        (Array.isArray(value) && value.length === 0)
    );
}

/** Um elemento novo, como o BlockSanitizer::blank() o faria. */
export function blankNode(
    schema: BlockSchema,
    locales: string[],
    defaultLocale: string,
): EditorBlock {
    const settings: Record<string, unknown> = {};
    const content: Record<string, Record<string, unknown>> = Object.fromEntries(
        locales.map((locale) => [locale, {}]),
    );

    for (const field of schema.fields) {
        if (field.translatable) {
            content[defaultLocale][field.key] = emptyValue(field);
        } else {
            settings[field.key] = emptyValue(field);
        }
    }

    return {
        id: randomId(),
        type: schema.type,
        v: 1,
        visible: true,
        label: null,
        variant: schema.variants[0]?.value ?? null,
        style: { ...schema.defaultStyle },
        shell: schema.hasShell,
        styleProps: {},
        advanced: {},
        settings,
        content,
    };
}

/**
 * O nome de um nó: o que o dono lhe deu, o do tipo de elemento ou, nos
 * contentores, "Contentor · 2 colunas" (dentro de uma linha, "Coluna 60%").
 */
export function nodeLabel(
    node: EditorNode,
    schema: Record<string, BlockSchema | undefined>,
    parent: EditorContainer | null = null,
): string {
    if (node.label) {
        return node.label;
    }

    if (!isEditorContainer(node)) {
        return schema[node.type]?.label ?? node.type;
    }

    const width = node.style.width?.desktop;
    const name =
        parent?.style.direction?.desktop !== 'row'
            ? 'Contentor'
            : typeof width === 'number'
              ? `Coluna ${width}%`
              : 'Coluna';

    if (node.style.direction?.desktop !== 'row') {
        return name;
    }

    const columns = columnCount(node);

    return `${name} · ${columns} ${columns === 1 ? 'coluna' : 'colunas'}`;
}

/** Os elementos lá dentro que a impedem de se apagar ou de se duplicar. */
export function subtreeFlags(
    node: EditorNode,
    schema: Record<string, BlockSchema | undefined>,
): { required: boolean; unique: boolean } {
    const types = walkNodes([node]).map((item) => schema[item.type]);

    return {
        required: types.some((item) => item?.required),
        unique: types.some((item) => item?.unique),
    };
}

/**
 * Os idiomas (fora o de omissão) em que falta pelo menos um campo de texto
 * preenchido em português — o ponto laranja dos separadores.
 */
export function missingTranslations(
    block: EditorBlock,
    schema: BlockSchema | undefined,
    locales: string[],
    defaultLocale: string,
): string[] {
    if (!schema) {
        return [];
    }

    const textual = schema.fields.filter(
        (field) =>
            field.translatable &&
            ['text', 'textarea', 'richtext', 'link', 'repeater'].includes(
                field.type,
            ),
    );
    const base = block.content[defaultLocale] ?? {};

    return locales.filter(
        (locale) =>
            locale !== defaultLocale &&
            textual.some(
                (field) =>
                    !isEmpty(base[field.key]) &&
                    isEmpty(block.content[locale]?.[field.key]),
            ),
    );
}
