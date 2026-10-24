import { Link, usePage } from '@inertiajs/react';
import { Check, Globe } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { localizedUrl, useLocale, useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * Troca de idioma sem sair da página: o mesmo caminho, com outro prefixo
 * ("/produtos/vaso" → "/en/produtos/vaso"). Cada opção é um link a sério, no
 * próprio idioma ("English", "Français"), e diz ao browser em que língua está.
 */
export function LanguageSwitcher({ className }: { className?: string }) {
    const locale = useLocale();
    const t = useT();
    const { url } = usePage();

    if (locale.available.length < 2) {
        return null;
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="sm"
                    className={cn('gap-1.5 uppercase', className)}
                    aria-label={t('header.language')}
                >
                    <Globe aria-hidden="true" className="size-4" />
                    {locale.current}
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="store-theme">
                {locale.available.map((option) => (
                    <DropdownMenuItem key={option.code} asChild>
                        <Link
                            href={localizedUrl(url, option.code, locale)}
                            hrefLang={option.intl}
                            lang={option.code}
                            className="flex items-center justify-between gap-6"
                        >
                            {option.name}
                            {option.code === locale.current && (
                                <Check aria-hidden="true" className="size-4" />
                            )}
                        </Link>
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/** Versão em linha para o rodapé e para o menu do telemóvel. */
export function LanguageLinks({ className }: { className?: string }) {
    const locale = useLocale();
    const t = useT();
    const { url } = usePage();

    if (locale.available.length < 2) {
        return null;
    }

    return (
        <nav aria-label={t('header.language')} className={className}>
            <ul className="flex flex-wrap gap-x-4 gap-y-2">
                {locale.available.map((option) => (
                    <li key={option.code}>
                        <Link
                            href={localizedUrl(url, option.code, locale)}
                            hrefLang={option.intl}
                            lang={option.code}
                            aria-current={
                                option.code === locale.current
                                    ? 'true'
                                    : undefined
                            }
                            className="text-muted-foreground transition-colors hover:text-foreground aria-[current=true]:font-medium aria-[current=true]:text-foreground"
                        >
                            {option.name}
                        </Link>
                    </li>
                ))}
            </ul>
        </nav>
    );
}
