export type CategoryRow = {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    status: string;
    /** Hex da paleta fixa, ou null enquanto ninguém escolheu uma. */
    color: string | null;
    sortOrder: number;
    productsCount: number;
};

export type CategoryDetail = {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    status: string;
    color: string | null;
    sortOrder: number;
};

export type CategoryFormData = {
    name: string;
    description: string;
    status: string;
    color: string | null;
    sort_order: number;
};

export type CategoryOption = {
    id: number;
    name: string;
};

/**
 * Estado derivado no servidor por `Material::state()`. Não é coluna: sai de
 * `active` cruzado com as bobines em stock, e o TypeScript só o recebe feito
 * para nunca haver duas regras de "isto está em falta".
 */
export type MaterialState = 'active' | 'low_stock' | 'archived';

/** Swatch de uma cor do material na listagem. */
export type MaterialColorChip = {
    name: string;
    hex: string;
};

export type MaterialRow = {
    id: number;
    name: string;
    family: string | null;
    supplier: string | null;
    pricePerKgCents: number;
    spoolsInStock: number;
    /** 0 = sem alerta de stock. */
    minSpools: number;
    active: boolean;
    sortOrder: number;
    state: MaterialState;
    /** Só as cores ativas, já ordenadas. O corte para "+N" é do componente. */
    colors: MaterialColorChip[];
};

export type MaterialStats = {
    activeCount: number;
    spoolsTotal: number;
    /** Média sobre os não arquivados; 0 quando ainda não há materiais. */
    averagePricePerKgCents: number;
    belowMinimumCount: number;
};

export type MaterialDetail = {
    id: number;
    name: string;
    family: string | null;
    supplier: string | null;
    /** Decimal em euros ("21.90") — o formulário edita euros. */
    pricePerKg: string;
    spoolsInStock: number;
    minSpools: number;
    active: boolean;
    sortOrder: number;
};

export type MaterialFormData = {
    name: string;
    family: string;
    supplier: string;
    price_per_kg: string;
    spools_in_stock: number;
    min_spools: number;
    active: boolean;
    sort_order: number;
};

/** Preset da paleta de filamento (App\Support\FilamentPalette). */
export type PaletteColor = {
    name: string;
    hex: string;
};

/**
 * Criar material no modal da listagem. Chaves em snake_case porque espelham as
 * regras do StoreMaterialRequest; `colors` são nomes de presets da paleta, e é
 * o servidor que lhes descobre o hex.
 */
export type MaterialQuickFormData = {
    name: string;
    family: string;
    supplier: string;
    /** Euros escritos à mão; o servidor converte para cêntimos. */
    price_per_kg: string;
    min_spools: number;
    colors: string[];
};

/**
 * Uma cor não tem stock — os materiais é que têm. `low_stock` é derivado no
 * servidor e só quando TODOS os materiais onde a cor existe estão em falta:
 * enquanto houver um com bobines, a cor imprime-se.
 */
export type ColorState = 'active' | 'low_stock' | 'archived';

/**
 * De onde vem o preço/kg da cor. `mixed` é o caso que o desenho não previa mas
 * os dados permitem: preço próprio nuns materiais e herdado noutros.
 */
export type ColorPriceOrigin = 'none' | 'inherited' | 'own' | 'mixed';

/** Material onde a cor existe, com o preço/kg em vigor nessa combinação. */
export type ColorMaterialChip = {
    id: number;
    name: string;
    pricePerKgCents: number;
};

/**
 * Uma cor como o backoffice a pensa: um nome, espalhado pelas linhas `colors`
 * dos materiais onde existe.
 */
export type ColorGroupRow = {
    /** Linha representante — é por ela que as rotas alcançam o grupo. */
    id: number;
    name: string;
    hex: string;
    /** Só os materiais ativos; um grupo arquivado vem sem nenhum. */
    materials: ColorMaterialChip[];
    materialIds: number[];
    /** Null quando o grupo está todo arquivado e não sobra preço nenhum. */
    minPricePerKgCents: number | null;
    maxPricePerKgCents: number | null;
    priceOrigin: ColorPriceOrigin;
    /**
     * O preço próprio, só quando é UM valor em todos os materiais. Null quando
     * eles divergem — o modal abre o campo vazio a pedir o que os uniformiza,
     * em vez de escolher um por conta própria.
     */
    ownPricePerKgCents: number | null;
    /** Soma de todas as linhas, arquivadas incluídas. */
    variantsCount: number;
    state: ColorState;
};

/** Cartão da vista "Por material" — e as chips de material do modal. */
export type ColorMaterialCard = {
    id: number;
    name: string;
    pricePerKgCents: number;
    active: boolean;
    colors: {
        name: string;
        hex: string;
        /** Só quando difere do material — senão o cartão já o disse no topo. */
        ownPricePerKgCents: number | null;
    }[];
};

export type ColorStats = {
    activeCount: number;
    /** Linhas `colors` ativas: quantos pares cor × material as cores ocupam. */
    pairsCount: number;
    ownPriceCount: number;
    /** Cores disponíveis que nenhuma variante usa. */
    unusedCount: number;
};

/**
 * Criar ou editar uma cor no modal da listagem. Chaves em snake_case porque
 * espelham as regras do StoreColorRequest; o servidor grava uma linha por
 * material escolhido.
 */
export type ColorGroupFormData = {
    name: string;
    hex_color: string;
    material_ids: number[];
    /** Vazio = herda o preço de cada material. */
    price_per_kg: string;
};

/** Mesma convenção do MATERIAL_STATES: singular na pastilha, plural na chip. */
export const COLOR_STATES = [
    { value: 'active', label: 'Disponível', chipLabel: 'Disponíveis' },
    { value: 'low_stock', label: 'Stock baixo', chipLabel: 'Stock baixo' },
    { value: 'archived', label: 'Arquivada', chipLabel: 'Arquivadas' },
] as const;

export type ColorSummary = {
    id: number;
    name: string;
    hex: string;
    material: string;
};

/** Cores agrupadas por material, para o seletor da variante. */
export type ColorGroup = {
    material: string;
    colors: {
        id: number;
        name: string;
        hex: string;
        /** Preço/kg em vigor, para a margem ao vivo do modal de novo produto. */
        pricePerKgCents: number;
    }[];
};

export type ProductRow = {
    id: number;
    name: string;
    slug: string;
    status: string;
    featured: boolean;
    fulfillmentMode: string;
    productionTimeDays: number | null;
    category: string | null;
    imageUrl: string | null;
    variantsCount: number;
    /*
     * Referência, preço, gramagem e tempo são da VARIANTE, não do produto —
     * a listagem mostra os da default, que é a mesma que a montra usa para
     * anunciar o preço. Null enquanto o produto não tiver variantes.
     */
    sku: string | null;
    priceCents: number | null;
    filamentWeightGrams: number | null;
    printingTimeMinutes: number | null;
    /** Somado em todas as variantes: stock físico menos o reservado. */
    readyStock: number;
};

/**
 * O modal "Novo produto" da listagem: os campos do produto mais a matriz que
 * gera as variantes de uma vez. O resto do formulário (etiquetas, IVA, SEO,
 * destaque) vive na página de edição.
 */
export type ProductQuickFormData = {
    name: string;
    category_id: number | null;
    description: string;
    status: string;
    fulfillment_mode: string;
    production_time_days: number | null;
    vat_rate: number;
    variants: {
        color_ids: number[];
        sizes: string[];
        /** Euros escritos à mão; o servidor converte para cêntimos. */
        price: string;
        filament_weight_grams: number | null;
        printing_time_minutes: number | null;
    };
};

export type ProductDetail = {
    id: number;
    name: string;
    slug: string;
    categoryId: number | null;
    /** HTML sanitizado no servidor — o editor lê e escreve neste formato. */
    description: string | null;
    tags: string[];
    status: string;
    featured: boolean;
    vatRate: number;
    fulfillmentMode: string;
    productionTimeDays: number | null;
    allowBackorder: boolean;
    maxOpenProductionQty: number | null;
};

export type ProductFormData = {
    name: string;
    /** Vazio = gerado a partir do nome pelo ProductService. */
    slug: string;
    category_id: number | null;
    description: string;
    tags: string[];
    status: string;
    featured: boolean;
    vat_rate: number;
    fulfillment_mode: string;
    production_time_days: number | null;
    allow_backorder: boolean;
    max_open_production_qty: number | null;
};

export type ProductImageRow = {
    id: number;
    url: string;
    alt: string | null;
    isPrimary: boolean;
};

export type ProductSummary = {
    id: number;
    name: string;
};

export type VariantRow = {
    id: number;
    sku: string;
    sizeLabel: string | null;
    color: ColorSummary | null;
    /** Preço efetivo — o que o cliente paga. Já é o promocional quando há promoção. */
    priceCents: number;
    /** Preço riscado. Só existe quando a variante está em promoção. */
    compareAtCents: number | null;
    wholesalePriceCents: number | null;
    filamentWeightGrams: number | null;
    stock: number;
    reservedStock: number;
    availableStock: number;
    lowStock: boolean;
    isDefault: boolean;
    active: boolean;
};

export type VariantDetail = {
    id: number;
    sku: string;
    colorId: number | null;
    sizeLabel: string | null;
    /** Decimais em euros ("12.50") — o formulário edita euros, a BD guarda cêntimos. */
    normalPrice: string;
    salePrice: string | null;
    wholesalePrice: string | null;
    filamentWeightGrams: number | null;
    stock: number;
    reservedStock: number;
    lowStockThreshold: number;
    isDefault: boolean;
    active: boolean;
};

export type VariantFormData = {
    sku: string;
    color_id: number | null;
    size_label: string;
    normal_price: string;
    sale_price: string;
    wholesale_price: string;
    filament_weight_grams: number | null;
    stock: number;
    low_stock_threshold: number;
    is_default: boolean;
    active: boolean;
};

/** Variante escolhível numa encomenda manual. */
export type VariantOption = {
    id: number;
    label: string;
    /** Só a cor e o tamanho ("PETG Natural"), sem o nome do produto à frente. */
    variantLabel: string;
    sku: string;
    priceCents: number;
    wholesalePriceCents: number | null;
    availableStock: number;
    vatRate: number;
    fulfillmentMode: string;
    productName: string;
};

/**
 * O `chipLabel` é o mesmo estado no plural: a pastilha da linha fala de UM
 * produto ("Rascunho") e a chip do filtro fala do conjunto ("Rascunhos 3").
 * Vivem no mesmo sítio para não haver duas listas de estados a divergir.
 */
export const PRODUCT_STATUSES = [
    { value: 'draft', label: 'Rascunho', chipLabel: 'Rascunhos' },
    { value: 'active', label: 'Ativo', chipLabel: 'Ativos' },
    { value: 'archived', label: 'Arquivado', chipLabel: 'Arquivados' },
] as const;

/**
 * Mesma convenção do PRODUCT_STATUSES. "Stock baixo" não tem plural próprio —
 * a chip e a pastilha dizem o mesmo, e inventar-lhe um ("Stocks baixos") só
 * soava mal.
 */
export const MATERIAL_STATES = [
    { value: 'active', label: 'Ativo', chipLabel: 'Ativos' },
    { value: 'low_stock', label: 'Stock baixo', chipLabel: 'Stock baixo' },
    { value: 'archived', label: 'Arquivado', chipLabel: 'Arquivados' },
] as const;

export const FULFILLMENT_MODES = [
    { value: 'in_stock', label: 'Em stock (já impresso)' },
    { value: 'made_to_order', label: 'Por encomenda' },
    { value: 'custom', label: 'Personalizado' },
] as const;

/** Mesma convenção do PRODUCT_STATUSES: singular na pastilha, plural na chip. */
export const CATEGORY_STATUSES = [
    { value: 'visible', label: 'Visível', chipLabel: 'Visíveis' },
    { value: 'hidden', label: 'Oculta', chipLabel: 'Ocultas' },
    { value: 'archived', label: 'Arquivada', chipLabel: 'Arquivadas' },
] as const;

/**
 * Paleta fixa das categorias — gémea de App\Support\CategoryColors.
 *
 * As duas listas existem porque cada lado precisa dela para uma coisa
 * diferente (o PHP valida, o React desenha) e passá-la como prop em todas as
 * páginas era carregar sete constantes em cada resposta. O `CategoryColorsTest`
 * compara-as, para não se separarem em silêncio.
 */
export const CATEGORY_COLORS = [
    { hex: '#C6A77B', name: 'Bege' },
    { hex: '#B0684A', name: 'Terracota' },
    { hex: '#D9A84E', name: 'Dourado' },
    { hex: '#8FAE7F', name: 'Verde musgo' },
    { hex: '#7C93A9', name: 'Azul pedra' },
    { hex: '#A9829C', name: 'Malva' },
    { hex: '#7C6B5C', name: 'Neutra' },
] as const;
