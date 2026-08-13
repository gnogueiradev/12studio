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
    /** Quantas variantes dependem desta bobine — e porque não se apaga. */
    variantsCount: number;
};

export type MaterialStats = {
    activeCount: number;
    spoolsTotal: number;
    /** Média sobre os não arquivados; 0 quando ainda não há materiais. */
    averagePricePerKgCents: number;
    belowMinimumCount: number;
};

/**
 * Nome e hex de uma chip ou swatch: os presets do App\Support\FilamentPalette,
 * o atalho para escolher um tom no modal de nova cor.
 */
export type PaletteColor = {
    name: string;
    hex: string;
};

/**
 * Criar e editar material no modal da listagem. Chaves em snake_case porque
 * espelham as regras do StoreMaterialRequest.
 *
 * Uma só forma para os dois modos. Sem `active`: quem arquiva e restaura são os
 * botões da linha, e uma chave que não vai no pedido é uma coluna que o
 * servidor não toca.
 */
export type MaterialFormData = {
    name: string;
    family: string;
    supplier: string;
    /** Euros escritos à mão; o servidor converte para cêntimos. */
    price_per_kg: string;
    spools_in_stock: number;
    min_spools: number;
    sort_order: number;
};

/**
 * Duas posições só. Uma cor não tem stock — quem fica sem bobines é o material,
 * e isso vive no MaterialState.
 */
export type ColorState = 'active' | 'archived';

/** Uma cor: um nome e um tom. */
export type ColorRow = {
    id: number;
    name: string;
    hex: string;
    image: string | null;
    sortOrder: number;
    variantsCount: number;
    state: ColorState;
};

export type ColorStats = {
    activeCount: number;
    archivedCount: number;
    /** Cores disponíveis que nenhuma variante usa. */
    unusedCount: number;
};

/**
 * Criar ou editar uma cor no modal da listagem. Chaves em snake_case porque
 * espelham as regras do StoreColorRequest.
 */
export type ColorFormData = {
    name: string;
    hex_color: string;
    sort_order: number;
};

/** Mesma convenção do MATERIAL_STATES: singular na pastilha, plural na chip. */
export const COLOR_STATES = [
    { value: 'active', label: 'Disponível', chipLabel: 'Disponíveis' },
    { value: 'archived', label: 'Arquivada', chipLabel: 'Arquivadas' },
] as const;

export type ColorSummary = {
    id: number;
    name: string;
    hex: string;
};

export type MaterialSummary = {
    id: number;
    name: string;
};

/** Cor escolhível numa variante. */
export type ColorOption = {
    id: number;
    name: string;
    hex: string;
};

/**
 * Material escolhível numa variante ou na calculadora. Traz o preço/kg porque é
 * dele que sai o custo de filamento — a cor não tem preço.
 */
export type MaterialOption = {
    id: number;
    name: string;
    family: string | null;
    pricePerKgCents: number;
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

/**
 * Um só formulário para o modal de produto, nos dois modos. As duas últimas
 * chaves são as que distinguem criar de editar e por isso nunca vão as duas no
 * mesmo pedido: a matriz e as fotografias são exclusivas da criação, e o
 * `transform` do modal tira-as antes de um `patch`. O `UpdateProductRequest`
 * também as descarta — uma chave que não vai no pedido é uma coluna que o
 * servidor não toca, e aqui são duas tabelas inteiras que ele não escreve.
 */
export type ProductFormData = {
    name: string;
    /** Vazio = gerado a partir do nome pelo ProductService. */
    slug: string;
    category_id: number | null;
    /** HTML do editor rico; o servidor sanitiza-o antes de gravar. */
    description: string;
    tags: string[];
    status: string;
    featured: boolean;
    vat_rate: number;
    fulfillment_mode: string;
    production_time_days: number | null;
    allow_backorder: boolean;
    max_open_production_qty: number | null;
    /** Só ao criar: viajam no mesmo POST, em multipart. */
    images: File[];
    /** Só ao criar: cor × material × tamanho, multiplicados no servidor. */
    variants: {
        color_ids: number[];
        material_ids: number[];
        sizes: string[];
        /** Euros escritos à mão; o servidor converte para cêntimos. */
        price: string;
        filament_weight_grams: number | null;
        printing_time_minutes: number | null;
    };
};

/**
 * O que o modal precisa para editar um produto, pedido por `?editar={id}` num
 * recarregamento parcial. Não vem da linha da listagem como nos materiais e nas
 * impressoras: a linha não traz categoria, descrição, etiquetas nem IVA, e a
 * galeria e as variantes são tabelas próprias.
 */
export type ProductEditing = {
    product: ProductDetail;
    images: ProductImageRow[];
    variants: VariantRow[];
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
    /** Ao lado da cor, não dentro dela: são dois eixos independentes. */
    material: MaterialSummary | null;
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
    materialId: number | null;
    sizeLabel: string | null;
    /** Decimais em euros ("12.50") — o formulário edita euros, a BD guarda cêntimos. */
    normalPrice: string;
    salePrice: string | null;
    wholesalePrice: string | null;
    filamentWeightGrams: number | null;
    /** Total em minutos: "1h30" são 90, nunca 1,30. */
    printingTimeMinutes: number | null;
    /** Null = a impressora predefinida. */
    printerProfileId: number | null;
    /** Decimais em euros; ímanes, feltro, caixa. */
    extraCost: string | null;
    stock: number;
    reservedStock: number;
    lowStockThreshold: number;
    isDefault: boolean;
    active: boolean;
};

export type VariantFormData = {
    sku: string;
    color_id: number | null;
    material_id: number | null;
    size_label: string;
    normal_price: string;
    sale_price: string;
    wholesale_price: string;
    filament_weight_grams: number | null;
    printing_time_minutes: number | null;
    printer_profile_id: number | null;
    extra_cost: string;
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
