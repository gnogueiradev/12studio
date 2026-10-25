import { Languages, X } from 'lucide-react';
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
import type {
    BlockSchema,
    EditorBlock,
    EditorBlockStyle,
} from '@/types/page-builder';
import { isEmpty, missingTranslations } from './blocks';
import { FieldInput } from './field-input';
import type { FieldContext } from './field-input';

export type StyleOptions = Record<
    keyof EditorBlockStyle,
    { value: string; label: string }[]
>;

const STYLE_LABELS: Record<keyof EditorBlockStyle, string> = {
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

type Props = {
    block: EditorBlock;
    index: number;
    schema: BlockSchema;
    styles: StyleOptions;
    locales: string[];
    defaultLocale: string;
    locale: string;
    onLocale: (locale: string) => void;
    onChange: (block: EditorBlock) => void;
    onClose: () => void;
    context: FieldContext;
};

/**
 * O inspetor do bloco selecionado: conteúdo (por idioma, com um ponto onde
 * falta tradução), opções que não mudam de idioma, a variante e o estilo.
 * Os campos geram-se do esquema do servidor.
 */
export function Inspector({
    block,
    index,
    schema,
    styles,
    locales,
    defaultLocale,
    locale,
    onLocale,
    onChange,
    onClose,
    context,
}: Props) {
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
            <div className="flex items-start gap-2">
                <div className="min-w-0 flex-1">
                    <h2 className="font-medium">{schema.label}</h2>
                    {schema.description && (
                        <p className="text-sm text-muted-foreground">
                            {schema.description}
                        </p>
                    )}
                </div>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="size-8"
                    aria-label="Fechar o inspetor"
                    onClick={onClose}
                >
                    <X />
                </Button>
            </div>

            {schema.variants.length > 1 && (
                <div className="grid gap-1.5">
                    <Label htmlFor={`${block.id}-variant`}>Variante</Label>
                    <Select
                        value={block.variant ?? schema.variants[0].value}
                        onValueChange={(variant) =>
                            onChange({ ...block, variant })
                        }
                    >
                        <SelectTrigger id={`${block.id}-variant`}>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {schema.variants.map((variant) => (
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

            {translatable.length > 0 && (
                <section className="grid gap-3" aria-label="Conteúdo">
                    <div className="flex items-center justify-between gap-2">
                        <h3 className="text-sm font-medium">Conteúdo</h3>
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
                    </div>
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
                            path={`blocks.${index}.content.${locale}.${field.key}`}
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
                            path={`blocks.${index}.settings.${field.key}`}
                            context={context}
                        />
                    ))}
                </section>
            )}

            <section className="grid gap-3" aria-label="Estilo">
                <h3 className="text-sm font-medium">Estilo</h3>
                <div className="grid grid-cols-2 gap-3">
                    {(
                        Object.keys(STYLE_LABELS) as (keyof EditorBlockStyle)[]
                    ).map((key) => (
                        <div key={key} className="grid gap-1.5">
                            <Label htmlFor={`${block.id}-style-${key}`}>
                                {STYLE_LABELS[key]}
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
                                <SelectTrigger id={`${block.id}-style-${key}`}>
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
                        </div>
                    ))}
                </div>
            </section>
        </div>
    );
}
