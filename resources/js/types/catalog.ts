export type CategoryRow = {
    id: number;
    name: string;
    slug: string;
    active: boolean;
    sortOrder: number;
    productsCount: number;
};

export type CategoryDetail = {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    active: boolean;
    sortOrder: number;
};

export type CategoryFormData = {
    name: string;
    description: string;
    active: boolean;
    sort_order: number;
};

export type CategoryOption = {
    id: number;
    name: string;
};

export type MaterialRow = {
    id: number;
    name: string;
    pricePerKgCents: number;
    active: boolean;
    sortOrder: number;
    colorsCount: number;
};

export type MaterialDetail = {
    id: number;
    name: string;
    /** Decimal em euros ("21.90") — o formulário edita euros. */
    pricePerKg: string;
    active: boolean;
    sortOrder: number;
};

export type MaterialFormData = {
    name: string;
    price_per_kg: string;
    active: boolean;
    sort_order: number;
};

export type MaterialOption = {
    id: number;
    name: string;
    /** Preço/kg do material, para mostrar o que a cor herda se não fizer override. */
    pricePerKgCents: number;
};

export type ColorRow = {
    id: number;
    name: string;
    hexColor: string;
    material: string;
    materialId: number;
    /** Preço/kg em vigor: o override da cor, ou o herdado do material. */
    effectivePricePerKgCents: number;
    hasOwnPrice: boolean;
    isActive: boolean;
    sortOrder: number;
    variantsCount: number;
};

export type ColorDetail = {
    id: number;
    materialId: number;
    name: string;
    hexColor: string;
    /** Vazio quando a cor herda o preço do material. */
    pricePerKg: string | null;
    isActive: boolean;
    sortOrder: number;
};

export type ColorFormData = {
    material_id: number | null;
    name: string;
    hex_color: string;
    price_per_kg: string;
    is_active: boolean;
    sort_order: number;
};

export type ColorSummary = {
    id: number;
    name: string;
    hex: string;
    material: string;
};

/** Cores agrupadas por material, para o seletor da variante. */
export type ColorGroup = {
    material: string;
    colors: { id: number; name: string; hex: string }[];
};

export type ProductRow = {
    id: number;
    name: string;
    slug: string;
    status: string;
    featured: boolean;
    fulfillmentMode: string;
    category: string | null;
    variantsCount: number;
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

export const PRODUCT_STATUSES = [
    { value: 'draft', label: 'Rascunho' },
    { value: 'active', label: 'Ativo' },
    { value: 'archived', label: 'Arquivado' },
] as const;

export const FULFILLMENT_MODES = [
    { value: 'in_stock', label: 'Em stock (já impresso)' },
    { value: 'made_to_order', label: 'Por encomenda' },
    { value: 'custom', label: 'Personalizado' },
] as const;
