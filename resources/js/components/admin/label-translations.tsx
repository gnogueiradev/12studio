import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/** Os idiomas da montra além do português (config/locales.php). */
export const TRANSLATION_LOCALES = ['es', 'en', 'fr'] as const;

const NAMES: Record<string, string> = {
    es: 'Espanhol',
    en: 'Inglês',
    fr: 'Francês',
};

/**
 * Um rótulo curto nos outros idiomas da montra (cores e materiais: aparecem
 * nos filtros e nas especificações). Vazio num idioma, fica o português.
 */
export function LabelTranslations({
    idPrefix,
    value,
    onChange,
    placeholder,
}: {
    idPrefix: string;
    value: Record<string, string>;
    onChange: (value: Record<string, string>) => void;
    placeholder?: string;
}) {
    return (
        <fieldset className="grid gap-2">
            <legend className="mb-1 text-sm font-medium">
                Nome na montra noutros idiomas
            </legend>
            <div className="grid gap-2 sm:grid-cols-3">
                {TRANSLATION_LOCALES.map((locale) => (
                    <div key={locale} className="grid gap-1">
                        <Label
                            htmlFor={`${idPrefix}-${locale}`}
                            className="text-xs text-muted-foreground"
                        >
                            {NAMES[locale]}
                        </Label>
                        <Input
                            id={`${idPrefix}-${locale}`}
                            value={value[locale] ?? ''}
                            maxLength={60}
                            placeholder={placeholder}
                            onChange={(event) =>
                                onChange({
                                    ...value,
                                    [locale]: event.target.value,
                                })
                            }
                        />
                    </div>
                ))}
            </div>
            <p className="text-xs text-muted-foreground">
                Vazio num idioma: a montra mostra o nome em português.
            </p>
        </fieldset>
    );
}
