import { Link, usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { LanguageLinks } from '@/components/store/language-switcher';
import { MenuLink } from '@/components/store/menu-link';
import { SocialIcon } from '@/components/store/social-icon';
import { useLocalizedUrl, useT } from '@/lib/i18n';
import { home } from '@/routes';
import type { Storefront } from '@/types/store';

const LINK_CLASS =
    'text-muted-foreground transition-colors hover:text-foreground';

/**
 * Rodapé da montra em quatro colunas: a loja (frase e redes), o catálogo (as
 * categorias visíveis) e as colunas do menu de rodapé (ajuda e legal, por
 * omissão). Em baixo, os idiomas e os direitos.
 */
export function StoreFooter({ storefront }: { storefront: Storefront }) {
    const { brand } = usePage().props;
    const t = useT();
    const localized = useLocalizedUrl();
    const year = new Date().getFullYear();

    return (
        <footer className="mt-24 border-t border-border/60 bg-secondary/40">
            <div className="mx-auto grid w-full max-w-6xl gap-10 px-4 py-14 sm:grid-cols-2 lg:grid-cols-4">
                <div className="flex flex-col gap-4">
                    <Link
                        href={localized(home().url)}
                        className="flex items-center gap-2.5 text-lg font-semibold tracking-tight"
                    >
                        <AppLogoIcon
                            aria-hidden="true"
                            className="h-6 w-auto fill-current"
                        />
                        {brand.name}
                    </Link>
                    {brand.tagline && (
                        <p className="max-w-xs text-sm text-muted-foreground">
                            {brand.tagline}
                        </p>
                    )}
                    {storefront.social.length > 0 && (
                        <ul
                            aria-label={t('footer.follow_us')}
                            className="flex flex-wrap gap-2"
                        >
                            {storefront.social.map((link) => (
                                <li key={link.network}>
                                    <a
                                        href={link.url}
                                        target="_blank"
                                        rel="noreferrer"
                                        aria-label={link.label}
                                        className="flex size-9 items-center justify-center rounded-full border border-border text-muted-foreground transition-colors hover:border-foreground/40 hover:text-foreground"
                                    >
                                        <SocialIcon
                                            network={link.network}
                                            className="size-4"
                                        />
                                    </a>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>

                {storefront.categories.length > 0 && (
                    <nav aria-labelledby="footer-catalog">
                        <h2
                            id="footer-catalog"
                            className="text-sm font-semibold"
                        >
                            {t('footer.catalog')}
                        </h2>
                        <ul className="mt-4 flex flex-col gap-2.5 text-sm">
                            {storefront.categories.map((category) => (
                                <li key={category.href}>
                                    <Link
                                        href={category.href}
                                        className={LINK_CLASS}
                                    >
                                        {category.label}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </nav>
                )}

                {storefront.menus.footer.map((column, index) => (
                    <nav
                        key={column.href + index}
                        aria-labelledby={`footer-column-${index}`}
                    >
                        <h2
                            id={`footer-column-${index}`}
                            className="text-sm font-semibold"
                        >
                            {column.label}
                        </h2>
                        <ul className="mt-4 flex flex-col gap-2.5 text-sm">
                            {column.children.map((item) => (
                                <li key={item.href + item.label}>
                                    <MenuLink
                                        item={item}
                                        className={LINK_CLASS}
                                    />
                                </li>
                            ))}
                        </ul>
                    </nav>
                ))}
            </div>

            <div className="border-t border-border/60">
                <div className="mx-auto flex w-full max-w-6xl flex-col gap-4 px-4 py-6 text-sm text-muted-foreground md:flex-row md:items-center md:justify-between">
                    <p>
                        © {year} {brand.name}. {t('footer.rights')}
                    </p>
                    <LanguageLinks />
                </div>
            </div>
        </footer>
    );
}
