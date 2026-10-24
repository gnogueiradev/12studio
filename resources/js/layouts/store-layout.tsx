import { Link, usePage } from '@inertiajs/react';
import { EyeOff } from 'lucide-react';
import { ContactFab } from '@/components/store/contact-fab';
import { StoreFooter } from '@/components/store/store-footer';
import { StoreHeader } from '@/components/store/store-header';
import { useT } from '@/lib/i18n';
import { index as storeSettings } from '@/routes/admin/montra';
import type { Storefront } from '@/types/store';

const EMPTY_STOREFRONT: Storefront = {
    menus: { header: [], footer: [] },
    contact: {},
    social: [],
    categories: [],
};

/**
 * Layout da montra pública: header, conteúdo, rodapé e o botão "Fala
 * connosco". Os menus, os contactos e as redes vêm do servidor (prop
 * `storefront`) já no idioma da página; os textos fixos, do useT().
 *
 * A raiz leva `store-theme`: é aí que o tema da loja (ThemeSettings) muda as
 * cores e os cantos. Os painéis que abrem fora dela (menus, diálogos) levam a
 * mesma classe.
 */
export default function StoreLayout({
    children,
}: {
    children: React.ReactNode;
}) {
    const { storePreview, storefront = EMPTY_STOREFRONT } = usePage().props;
    const t = useT();

    return (
        <div className="store-theme flex min-h-screen flex-col bg-background text-foreground">
            <a
                href="#conteudo"
                className="sr-only z-50 rounded-md bg-primary px-4 py-2 text-primary-foreground focus:not-sr-only focus:fixed focus:top-3 focus:left-3"
            >
                {t('header.skip_to_content')}
            </a>

            {storePreview && (
                <div
                    role="status"
                    className="flex flex-wrap items-center justify-center gap-x-3 gap-y-1 bg-foreground px-4 py-2 text-center text-sm text-background"
                >
                    <EyeOff aria-hidden="true" className="size-4" />
                    <span>{t('preview.closed')}</span>
                    <Link
                        href={storeSettings()}
                        className="font-medium underline underline-offset-4"
                    >
                        {t('preview.open_store')}
                    </Link>
                </div>
            )}

            <StoreHeader items={storefront.menus.header} />

            <main id="conteudo" className="flex-1">
                {children}
            </main>

            <StoreFooter storefront={storefront} />

            <ContactFab
                contact={storefront.contact}
                social={storefront.social}
            />
        </div>
    );
}
