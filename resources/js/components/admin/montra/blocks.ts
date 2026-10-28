import type {
    BlockSchema,
    EditorBlock,
    FieldSchema,
} from '@/types/page-builder';

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
        id: crypto.randomUUID(),
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
        id: crypto.randomUUID(),
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
