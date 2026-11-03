import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

/** Os idiomas da montra além do português (config/locales.php). */
export const TRANSLATION_LOCALES = ['es', 'en', 'fr'] as const;

const NAMES: Record<string, string> = {
    es: 'Espanhol',
    en: 'Inglês',
    fr: 'Francês',
};

/**
 * Um rótulo curto nos outros idiomas da montra (cores e materiais: aparecem
 * nos filtros e nas especificações; o texto da faixa de aviso). Vazio num
 * idioma, fica o português.
 */
export function LabelTranslations({
    idPrefix,
    value,
    onChange,
    placeholder,
    legend = 'Nome na montra noutros idiomas',
    maxLength = 60,
    stacked = false,
}: {
    idPrefix: string;
    value: Record<string, string>;
    onChange: (value: Record<string, string>) => void;
    placeholder?: string;
    legend?: string;
    maxLength?: number;
    /** Um idioma por linha: para textos que não cabem num terço da largura. */
    stacked?: boolean;
}) {
    return (
        <fieldset className="grid gap-2">
            <legend className="mb-1 text-sm font-medium">{legend}</legend>
            <div className={cn('grid gap-2', !stacked && 'sm:grid-cols-3')}>
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
                            maxLength={maxLength}
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
                Vazio num idioma: a montra mostra o português.
            </p>
        </fieldset>
    );
}
