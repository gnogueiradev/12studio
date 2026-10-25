import { Link, router, usePage } from '@inertiajs/react';
import { Menu, Search } from 'lucide-react';
import { useState } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import {
    LanguageLinks,
    LanguageSwitcher,
} from '@/components/store/language-switcher';
import { MenuLink } from '@/components/store/menu-link';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { useLocalizedUrl, useT } from '@/lib/i18n';
import { home } from '@/routes';
import type { MenuItem } from '@/types/store';

/** O link do menu em que se está: o caminho atual começa por ele. */
function isCurrent(item: MenuItem, url: string): boolean {
    if (item.external || !item.href.startsWith('/')) {
        return false;
    }

    const path = url.split('?')[0];

    return path === item.href || path.startsWith(`${item.href}/`);
}

export function StoreHeader({
    items,
    logo = null,
}: {
    items: MenuItem[];
    /** O logotipo do tema; sem ele, o símbolo e o nome da loja. */
    logo?: string | null;
}) {
    const { brand } = usePage().props;
    const { url } = usePage();
    const t = useT();
    const localized = useLocalizedUrl();

    const [menuOpen, setMenuOpen] = useState(false);
    const [searchOpen, setSearchOpen] = useState(false);
    const [query, setQuery] = useState('');

    const search = (event: React.FormEvent) => {
        event.preventDefault();

        const q = query.trim();

        if (q === '') {
            return;
        }

        setSearchOpen(false);
        setMenuOpen(false);
        router.get(localized('/produtos'), { q });
    };

    return (
        <header className="sticky top-0 z-40 border-b border-border/60 bg-background/90 backdrop-blur supports-[backdrop-filter]:bg-background/75">
            <div className="mx-auto flex h-16 w-full max-w-6xl items-center gap-4 px-4">
                <Link
                    href={localized(home().url)}
                    className="flex shrink-0 items-center gap-2.5 rounded-md text-lg font-semibold tracking-tight"
                >
                    {logo ? (
                        <img
                            src={logo}
                            alt={brand.name}
                            className="h-8 w-auto max-w-40 object-contain"
                        />
                    ) : (
                        <>
                            <AppLogoIcon
                                aria-hidden="true"
                                className="h-6 w-auto fill-current"
                            />
                            {brand.name}
                        </>
                    )}
                </Link>

                <nav
                    aria-label={t('header.main_nav')}
                    className="ml-6 hidden md:block"
                >
                    <ul className="flex items-center gap-1">
                        {items.map((item) => (
                            <li key={item.href}>
                                <MenuLink
                                    item={item}
                                    aria-current={
                                        isCurrent(item, url)
                                            ? 'page'
                                            : undefined
                                    }
                                    className="rounded-md px-3 py-2 text-sm text-muted-foreground transition-colors hover:text-foreground aria-[current=page]:text-foreground"
                                />
                            </li>
                        ))}
                    </ul>
                </nav>

                <div className="ml-auto flex items-center gap-1">
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label={t('header.search')}
                        onClick={() => setSearchOpen(true)}
                    >
                        <Search aria-hidden="true" />
                    </Button>

                    <LanguageSwitcher className="hidden md:inline-flex" />

                    <Sheet open={menuOpen} onOpenChange={setMenuOpen}>
                        <SheetTrigger asChild>
                            <Button
                                variant="ghost"
                                size="icon"
                                className="md:hidden"
                                aria-label={t('header.open_menu')}
                            >
                                <Menu aria-hidden="true" />
                            </Button>
                        </SheetTrigger>
                        <SheetContent
                            side="left"
                            className="store-theme flex w-[85vw] max-w-sm flex-col bg-background text-foreground"
                        >
                            <SheetHeader className="border-b border-border/60">
                                <SheetTitle>{t('header.menu')}</SheetTitle>
                            </SheetHeader>
                            <nav
                                aria-label={t('header.main_nav')}
                                className="px-4"
                            >
                                <ul className="flex flex-col">
                                    {items.map((item) => (
                                        <li key={item.href}>
                                            <MenuLink
                                                item={item}
                                                onClick={() =>
                                                    setMenuOpen(false)
                                                }
                                                aria-current={
                                                    isCurrent(item, url)
                                                        ? 'page'
                                                        : undefined
                                                }
                                                className="block border-b border-border/40 py-4 text-lg aria-[current=page]:font-medium"
                                            />
                                        </li>
                                    ))}
                                </ul>
                            </nav>
                            <LanguageLinks className="mt-auto border-t border-border/60 p-4 text-sm" />
                        </SheetContent>
                    </Sheet>
                </div>
            </div>

            <Dialog open={searchOpen} onOpenChange={setSearchOpen}>
                <DialogContent className="store-theme top-[15%] translate-y-0 bg-background text-foreground sm:max-w-lg">
                    <DialogTitle className="sr-only">
                        {t('header.search')}
                    </DialogTitle>
                    <form
                        role="search"
                        onSubmit={search}
                        className="flex items-center gap-2"
                    >
                        <Search
                            aria-hidden="true"
                            className="size-5 shrink-0 text-muted-foreground"
                        />
                        <Input
                            type="search"
                            name="q"
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            placeholder={t('header.search_placeholder')}
                            aria-label={t('header.search')}
                            className="h-11 border-0 bg-transparent text-base shadow-none focus-visible:ring-0"
                            autoFocus
                        />
                    </form>
                </DialogContent>
            </Dialog>
        </header>
    );
}
