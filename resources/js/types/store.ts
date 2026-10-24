/**
 * Tipos da montra pública. Espelham os arrays que o servidor monta — nunca o
 * modelo inteiro: a montra só recebe o que mostra.
 */

/** ProductImage::responsive() */
export type ResponsiveImageData = {
    src: string;
    /** "…-320.webp 320w, …-640.webp 640w"; null até o job das versões reduzidas correr. */
    srcset: string | null;
    width: number | null;
    height: number | null;
    alt: string;
};

/** Um item de menu já resolvido no servidor (StoreMenus). */
export type MenuItem = {
    label: string;
    /** Já no idioma da página ("/en/produtos") ou externo ("https://…", "mailto:…"). */
    href: string;
    external: boolean;
    children: MenuItem[];
};

export type SocialLink = {
    network: string;
    label: string;
    url: string;
};

/** A prop `storefront` (ShareStorefront): o que o StoreLayout mostra. */
export type Storefront = {
    menus: { header: MenuItem[]; footer: MenuItem[] };
    contact: {
        email?: string;
        phone?: string;
        address?: string;
        hours?: string;
        responseHours?: number;
    };
    social: SocialLink[];
    categories: { label: string; href: string }[];
};
