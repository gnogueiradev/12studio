import { Link, usePage } from '@inertiajs/react';
import { EyeOff } from 'lucide-react';
import { useEffect } from 'react';
import { AnnouncementBar } from '@/components/store/announcement-bar';
import { ContactFab } from '@/components/store/contact-fab';
import { StoreFooter } from '@/components/store/store-footer';
import { StoreHeader } from '@/components/store/store-header';
import { useLocale, useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { index as storeSettings } from '@/routes/admin/montra';
import type { CartData } from '@/types/shop';
import type { Storefront } from '@/types/store';

const EMPTY_STOREFRONT: Storefront = {
    menus: { header: [], footer: [] },
    contact: {},
    social: [],
    categories: [],
    logo: null,
    announcement: null,
    form: { started: '' },
    shop: false,
    cartCount: 0,
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
    const cart = usePage().props.cart as CartData | undefined;
    const t = useT();
    const { component } = usePage();
    const { current, available, default: defaultCode } = useLocale();
    // O mesmo valor que o Blade escreve (config/locales.php, `hreflang`),
    // para o atributo não mudar de "en" para "en-IE" depois de hidratar.
    const langOf = (code: string) => {
        const option = available.find((item) => item.code === code);

        return option?.hreflang ?? option?.intl ?? 'pt-PT';
    };
    const lang = langOf(current);
    const defaultLang = langOf(defaultCode);

    // O <html lang> só é escrito pelo Blade na primeira carga. Trocar de
    // idioma é uma visita do Inertia, sem recarregar: sem isto o texto
    // passava a inglês e o leitor de ecrã continuava a lê-lo com voz
    // portuguesa (WCAG 3.1.1). Ao sair da montra para o backoffice — que é
    // sempre no idioma por omissão — o atributo volta a esse.
    useEffect(() => {
        document.documentElement.lang = lang;

        return () => {
            document.documentElement.lang = defaultLang;
        };
    }, [lang, defaultLang]);
    // Na conversa, o cliente já está a falar connosco: sem o botão.
    const conversationPage = component === 'store/conversation';

    // Na página de produto, o telemóvel tem uma barra fixa em baixo com o
    // preço e o "Tenho interesse": o rodapé ganha espaço para ela, e o botão
    // "Fala connosco" sai do caminho.
    const productPage = product !== undefined;
    // O carrinho com linhas tem a sua barra fixa (total e "Finalizar
    // compra"): o botão "Fala connosco" sai também do caminho.
    const cartBar = component === 'store/cart' && (cart?.lines.length ?? 0) > 0;

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

            {storefront.announcement && (
                <AnnouncementBar announcement={storefront.announcement} />
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
                    className={
                        productPage || cartBar ? 'max-md:hidden' : undefined
                    }
                />
            )}
        </div>
    );
}
