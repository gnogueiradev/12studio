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
    description: string | null;
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
    category_id: number | null;
    description: string;
    status: string;
    featured: boolean;
    vat_rate: number;
    fulfillment_mode: string;
    production_time_days: number | null;
    allow_backorder: boolean;
    max_open_production_qty: number | null;
};

export type ProductSummary = {
    id: number;
    name: string;
};

export type VariantRow = {
    id: number;
    sku: string;
    sizeLabel: string | null;
    priceCents: number;
    compareAtCents: number | null;
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
    sizeLabel: string | null;
    /** Decimal em euros ("12.50") — o formulário edita euros, a BD guarda cêntimos. */
    price: string;
    compareAtPrice: string | null;
    stock: number;
    reservedStock: number;
    lowStockThreshold: number;
    isDefault: boolean;
    active: boolean;
};

export type VariantFormData = {
    sku: string;
    size_label: string;
    price: string;
    compare_at_price: string;
    stock: number;
    low_stock_threshold: number;
    is_default: boolean;
    active: boolean;
};

/** Variante escolhível numa encomenda manual. */
export type VariantOption = {
    id: number;
    label: string;
    sku: string;
    priceCents: number;
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
