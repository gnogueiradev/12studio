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

/** Um link de bloco já resolvido (PageRenderer). */
export type BlockLink = {
    label: string;
    /** Já no idioma da página quando é interno. */
    href: string;
    external: boolean;
};

export type BlockStyle = {
    bg: 'default' | 'muted' | 'accent' | 'dark' | 'gold';
    spacing: 'sm' | 'md' | 'lg';
    width: 'contained' | 'full';
    align: 'start' | 'center';
};

/** Um bloco como o PageRenderer o entrega. */
export type StoreBlock = {
    id: string;
    type: string;
    variant: string | null;
    style: BlockStyle;
    data: Record<string, unknown>;
};

/** App\Store\ProductCard::make() */
export type ProductCardData = {
    id: number;
    name: string;
    slug: string;
    href: string;
    category: string | null;
    price: {
        from: number | null;
        to: number | null;
        compareAt: number | null;
        varies: boolean;
    };
    image: ResponsiveImageData | null;
    hoverImage: ResponsiveImageData | null;
    colors: { name: string; hex: string }[];
    moreColors: number;
    availability: 'ready' | 'made_to_order' | 'custom' | 'sold_out';
    isNew: boolean;
    onSale: boolean;
};

export type StorePageData = {
    key: string;
    title: string;
    /** O admin a ver uma página que ainda não está publicada. */
    draft: boolean;
    /** A página dentro do iframe do editor de páginas (só admin). */
    preview?: boolean;
    blocks: StoreBlock[];
};

/** A prop `catalog` (CatalogController). */
export type CatalogData = {
    /** O caminho da listagem, já no idioma ("/en/produtos", "/categoria/vasos"). */
    path: string;
    category: string | null;
    filters: {
        q: string;
        cor: number[];
        material: number[];
        preco_min: number | null;
        preco_max: number | null;
        disponibilidade: 'pronto' | 'encomenda' | null;
        ordem: string;
    };
    filtered: boolean;
    facets: {
        categories: { name: string; slug: string }[];
        colors: { id: number; name: string; hex: string }[];
        materials: { id: number; name: string }[];
        price: { min: number; max: number };
    };
    total: number;
};

/** O "carregar mais" do Inertia: a página atual e as que já se juntaram. */
export type ScrollData<T> = { data: T[] };

export type ProductVariantData = {
    id: number;
    colorId: number | null;
    materialId: number | null;
    size: string | null;
    price: number;
    compareAt: number | null;
    ready: boolean;
    specs: {
        length?: number;
        width?: number;
        height?: number;
        weight?: number;
    };
};

export type ProductImageData = ResponsiveImageData & {
    id: number;
    colorId: number | null;
    variantId: number | null;
};

/** App\Store\ProductDetail::make() */
export type ProductDetailData = {
    id: number;
    name: string;
    slug: string;
    href: string;
    description: string;
    category: { name: string; href: string } | null;
    price: ProductCardData['price'];
    availability: ProductCardData['availability'];
    leadDays: [number, number] | null;
    isCustom: boolean;
    images: ProductImageData[];
    colors: { id: number; name: string; hex: string }[];
    materials: { id: number; name: string }[];
    sizes: string[];
    variants: ProductVariantData[];
    defaultVariantId: number | null;
    personalization: {
        fields: {
            key: string;
            label: string;
            type: 'text' | 'number';
            required: boolean;
            maxLength: number | null;
        }[];
        surcharge: number | null;
    } | null;
    /** O admin a ver um produto que não está à venda. */
    preview: boolean;
};
