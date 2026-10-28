import { Link, usePage } from '@inertiajs/react';
import { EyeOff } from 'lucide-react';
import { ContactFab } from '@/components/store/contact-fab';
import { StoreFooter } from '@/components/store/store-footer';
import { StoreHeader } from '@/components/store/store-header';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { index as storeSettings } from '@/routes/admin/montra';
import type { Storefront } from '@/types/store';

const EMPTY_STOREFRONT: Storefront = {
    menus: { header: [], footer: [] },
    contact: {},
    social: [],
    categories: [],
    logo: null,
    form: { started: '' },
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
    const {
        storePreview,
        storefront = EMPTY_STOREFRONT,
        product,
    } = usePage().props;
    const t = useT();
    const { component } = usePage();
    // Na conversa, o cliente já está a falar connosco: sem o botão.
    const conversationPage = component === 'store/conversation';

    // Na página de produto, o telemóvel tem uma barra fixa em baixo com o
    // preço e o "Tenho interesse": o rodapé ganha espaço para ela, e o botão
    // "Fala connosco" sai do caminho.
    const productPage = product !== undefined;

    return (
        <div
            className={cn(
                'store-theme flex min-h-screen flex-col bg-background text-foreground',
                productPage && 'pb-20 md:pb-0',
            )}
        >
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

            <StoreHeader
                items={storefront.menus.header}
                logo={storefront.logo}
            />

            <main id="conteudo" className="flex-1">
                {children}
            </main>

            <StoreFooter storefront={storefront} />

            {!conversationPage && (
                <ContactFab
                    contact={storefront.contact}
                    social={storefront.social}
                    className={productPage ? 'max-md:hidden' : undefined}
                />
            )}
        </div>
    );
}
