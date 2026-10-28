import {
    Columns2,
    FileText,
    GalleryHorizontal,
    Grid3x3,
    HelpCircle,
    Image as ImageIcon,
    LayoutGrid,
    ListOrdered,
    Mail,
    Megaphone,
    MessageSquareQuote,
    PencilRuler,
    Rows3,
    ShieldCheck,
    Square,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { createElement } from 'react';
import { randomId } from '@/lib/utils';
import type {
    BlockSchema,
    EditorBlock,
    FieldSchema,
} from '@/types/page-builder';

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

/** Um bloco novo, como o BlockSanitizer::blank() o faria. */
export function blankBlock(
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
        variant: schema.variants[0]?.value ?? null,
        style: { ...schema.defaultStyle },
        settings,
        content,
    };
}

export function duplicateBlock(block: EditorBlock): EditorBlock {
    return {
        ...structuredClone(block),
        id: randomId(),
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

/** Um resumo curto do bloco para a lista (o título, se tiver). */
export function blockSummary(
    block: EditorBlock,
    defaultLocale: string,
): string {
    const content = block.content[defaultLocale] ?? {};

    for (const key of ['title', 'heading', 'eyebrow', 'text', 'body']) {
        const value = content[key];

        if (typeof value === 'string' && value.trim() !== '') {
            return value.replace(/<[^>]+>/g, ' ').trim();
        }
    }

    return '';
}
