import { useForm } from '@inertiajs/react';
import { Languages } from 'lucide-react';
import { useState } from 'react';
import { RichTextEditor } from '@/components/admin/rich-text-editor';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';

export type TranslationStatus = 'missing' | 'outdated' | 'ok';

export type ContentTranslation = {
    status: TranslationStatus;
    name: string;
    description: string;
    seo_title?: string;
    seo_description?: string;
    personalization?: Record<string, string>;
};

const LOCALE_NAMES: Record<string, string> = {
    es: 'Espanhol',
    en: 'Inglês',
    fr: 'Francês',
};

const STATUS: Record<TranslationStatus, { label: string; dot: string }> = {
    missing: { label: 'Em falta', dot: 'bg-warning' },
    outdated: { label: 'Desatualizada', dot: 'bg-destructive' },
    ok: { label: 'Em dia', dot: 'bg-success' },
};

type Props = {
    /** O URL de gravação de cada idioma. */
    url: (locale: string) => string;
    translations: Record<string, ContentTranslation>;
    /** O português, para mostrar ao lado. */
    original: { name: string; description: string | null };
    /** Produtos: SEO e rótulos de personalização; categorias: só nome e descrição. */
    withSeo?: boolean;
    descriptionAsRichText?: boolean;
    personalizationFields?: { key: string; label: string }[];
};

/**
 * O conteúdo noutros idiomas da montra (produtos e categorias), um separador
 * por idioma com o estado de cada um: em falta, desatualizada (o português
 * mudou depois de traduzido) ou em dia. O que ficar vazio aparece em
 * português. O Claude também traduz pelo MCP.
 */
export function ContentTranslations({
    url,
    translations,
    original,
    withSeo = false,
    descriptionAsRichText = false,
    personalizationFields = [],
}: Props) {
    const locales = Object.keys(translations);
    const [tab, setTab] = useState(locales[0]);

    if (locales.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                A montra só tem português ativo (Definições → Montra).
            </p>
        );
    }

    return (
        <Tabs value={tab} onValueChange={setTab}>
            <TabsList>
                {locales.map((locale) => (
                    <TabsTrigger key={locale} value={locale}>
                        {LOCALE_NAMES[locale] ?? locale.toUpperCase()}
                        <span
                            aria-hidden
                            className={cn(
                                'size-1.5 rounded-full',
                                STATUS[translations[locale].status].dot,
                            )}
                        />
                        <span className="sr-only">
                            ({STATUS[translations[locale].status].label})
                        </span>
                    </TabsTrigger>
                ))}
            </TabsList>
            {locales.map((locale) => (
                <TabsContent key={locale} value={locale} className="pt-3">
                    <TranslationForm
                        locale={locale}
                        url={url(locale)}
                        translation={translations[locale]}
                        original={original}
                        withSeo={withSeo}
                        descriptionAsRichText={descriptionAsRichText}
                        personalizationFields={personalizationFields}
                    />
                </TabsContent>
            ))}
        </Tabs>
    );
}

function TranslationForm({
    locale,
    url,
    translation,
    original,
    withSeo,
    descriptionAsRichText,
    personalizationFields,
}: {
    locale: string;
    url: string;
    translation: ContentTranslation;
    original: Props['original'];
    withSeo: boolean;
    descriptionAsRichText: boolean;
    personalizationFields: { key: string; label: string }[];
}) {
    const form = useForm({
        name: translation.name,
        description: translation.description,
        seo_title: translation.seo_title ?? '',
        seo_description: translation.seo_description ?? '',
        personalization: { ...(translation.personalization ?? {}) },
    });
    const id = (field: string) => `translation-${locale}-${field}`;
    const status = STATUS[translation.status];

    return (
        <form
            className="grid gap-4"
            onSubmit={(event) => {
                event.preventDefault();
                form.transform((data) =>
                    withSeo
                        ? data
                        : { name: data.name, description: data.description },
                );
                form.put(url, {
                    preserveScroll: true,
                    onSuccess: () => form.setDefaults(),
                });
            }}
        >
            <p className="flex items-center gap-2 text-sm text-muted-foreground">
                <Languages aria-hidden className="size-4" />
                {status.label}
                {translation.status === 'outdated' &&
                    ' — o português mudou depois desta tradução. Revê e grava.'}
                {translation.status === 'missing' &&
                    ' — a montra mostra o português neste idioma.'}
            </p>
            <div className="grid gap-1.5">
                <Label htmlFor={id('name')}>Nome</Label>
                <Input
                    id={id('name')}
                    value={form.data.name}
                    maxLength={160}
                    placeholder={original.name}
                    onChange={(event) =>
                        form.setData('name', event.target.value)
                    }
                />
                <InputError message={form.errors.name} />
            </div>
            <div className="grid gap-1.5">
                <Label htmlFor={id('description')}>Descrição</Label>
                {descriptionAsRichText ? (
                    <RichTextEditor
                        id={id('description')}
                        value={form.data.description}
                        onChange={(html) => form.setData('description', html)}
                    />
                ) : (
                    <Textarea
                        id={id('description')}
                        rows={3}
                        maxLength={2000}
                        placeholder={original.description ?? ''}
                        value={form.data.description}
                        onChange={(event) =>
                            form.setData('description', event.target.value)
                        }
                    />
                )}
                <InputError message={form.errors.description} />
            </div>
            {withSeo && (
                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="grid gap-1.5">
                        <Label htmlFor={id('seo-title')}>
                            Título para motores de busca
                        </Label>
                        <Input
                            id={id('seo-title')}
                            value={form.data.seo_title}
                            maxLength={70}
                            onChange={(event) =>
                                form.setData('seo_title', event.target.value)
                            }
                        />
                        <InputError message={form.errors.seo_title} />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor={id('seo-description')}>
                            Descrição para motores de busca
                        </Label>
                        <Input
                            id={id('seo-description')}
                            value={form.data.seo_description}
                            maxLength={170}
                            onChange={(event) =>
                                form.setData(
                                    'seo_description',
                                    event.target.value,
                                )
                            }
                        />
                        <InputError message={form.errors.seo_description} />
                    </div>
                </div>
            )}
            {withSeo && personalizationFields.length > 0 && (
                <fieldset className="grid gap-2">
                    <legend className="mb-1 text-sm font-medium">
                        Campos de personalização
                    </legend>
                    {personalizationFields.map((field) => (
                        <div
                            key={field.key}
                            className="grid gap-1 sm:grid-cols-[10rem_1fr] sm:items-center"
                        >
                            <Label
                                htmlFor={id(`p-${field.key}`)}
                                className="text-muted-foreground"
                            >
                                {field.label}
                            </Label>
                            <Input
                                id={id(`p-${field.key}`)}
                                value={
                                    form.data.personalization[field.key] ?? ''
                                }
                                maxLength={60}
                                onChange={(event) =>
                                    form.setData('personalization', {
                                        ...form.data.personalization,
                                        [field.key]: event.target.value,
                                    })
                                }
                            />
                        </div>
                    ))}
                </fieldset>
            )}
            <Button
                type="submit"
                variant="outline"
                className="justify-self-start"
                disabled={form.processing || !form.isDirty}
            >
                {form.processing && <Spinner />}
                Guardar {LOCALE_NAMES[locale]?.toLowerCase() ?? locale}
            </Button>
        </form>
    );
}
