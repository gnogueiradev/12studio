import { Link, usePage } from '@inertiajs/react';
import {
    FileText,
    Image as ImageIcon,
    Menu as MenuIcon,
    Paintbrush,
    Settings2,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';
import { index as settings } from '@/routes/admin/montra';
import { index as media } from '@/routes/admin/montra/media';
import { edit as menus } from '@/routes/admin/montra/menus';
import { index as pages } from '@/routes/admin/montra/paginas';
import { edit as theme } from '@/routes/admin/montra/tema';

type Section = 'paginas' | 'menus' | 'media' | 'tema';

/**
 * Os separadores da área Montra do backoffice. O tema só aparece ao dono
 * (o servidor dá 403 na mesma a quem não for).
 */
export function MontraNav({ current }: { current: Section }) {
    const { auth } = usePage().props;

    const items: {
        key: Section | 'definicoes';
        label: string;
        href: string;
        icon: LucideIcon;
    }[] = [
        { key: 'paginas', label: 'Páginas', href: pages().url, icon: FileText },
        { key: 'menus', label: 'Menus', href: menus().url, icon: MenuIcon },
        { key: 'media', label: 'Imagens', href: media().url, icon: ImageIcon },
        ...(auth.can.manageStaff
            ? [
                  {
                      key: 'tema' as const,
                      label: 'Tema',
                      href: theme().url,
                      icon: Paintbrush,
                  },
              ]
            : []),
        {
            key: 'definicoes',
            label: 'Contactos e estado',
            href: settings().url,
            icon: Settings2,
        },
    ];

    return (
        <nav
            aria-label="Montra"
            className="-mx-1 flex gap-1 overflow-x-auto border-b border-border/60 px-1"
        >
            {items.map(({ key, label, href, icon: Icon }) => (
                <Link
                    key={key}
                    href={href}
                    aria-current={key === current ? 'page' : undefined}
                    className={cn(
                        'flex shrink-0 items-center gap-2 border-b-2 px-3 py-2 text-sm transition-colors',
                        key === current
                            ? 'border-foreground font-medium text-foreground'
                            : 'border-transparent text-muted-foreground hover:text-foreground',
                    )}
                >
                    <Icon aria-hidden className="size-4" />
                    {label}
                </Link>
            ))}
        </nav>
    );
}
