import type { ProductProduction } from '@/lib/production';
import type { VariantFormData, VariantRow } from '@/types/catalog';

/** O stock de arranque de uma variante nova, e o limiar do aviso. */
const NEW_VARIANT = {
    stock: 0,
    low_stock_threshold: 3,
} as const;

/**
 * A linha de uma variante traduzida no formulário que a edita.
 *
 * Vive fora da ficha porque tem dois consumidores: a ficha, que abre com estes
 * valores, e o preço/stock editáveis na própria linha da lista — que gravam
 * pelo mesmo endpoint e por isso têm de enviar o mesmo payload completo. O
 * `UpdateVariantRequest` herda as regras do `store`: um patch só com o preço
 * era um pedido a que faltava o SKU.
 */
export function variantFormFromRow(variant: VariantRow): VariantFormData {
    return {
        sku: variant.sku,
        color_id: variant.colorId,
        material_id: variant.materialId,
        size_label: variant.sizeLabel ?? '',
        normal_price: variant.normalPrice,
        sale_price: variant.salePrice ?? '',
        wholesale_price: variant.wholesalePrice ?? '',
        filament_weight_grams: variant.filamentWeightGrams,
        printing_time_minutes: variant.printingTimeMinutes,
        printer_profile_id: variant.printerProfileId,
        packaging_cost: variant.packagingCost ?? '',
        components_cost: variant.componentsCost ?? '',
        active_labor_minutes: variant.activeLaborMinutes,
        stock: variant.stock,
        low_stock_threshold: variant.lowStockThreshold,
        is_default: variant.isDefault,
        active: variant.active,
    };
}

/**
 * Uma variante nova nasce com o tempo e a gramagem do produto: a peça é a mesma
 * em todas, e a secção "Impressão" escreve-os em todas de uma vez — uma ficha a
 * abrir vazia era a única que ficava fora dessa regra.
 */
export function newVariantForm(
    suggestedSku: string,
    production: ProductProduction,
): VariantFormData {
    return {
        sku: suggestedSku,
        color_id: null,
        material_id: null,
        size_label: '',
        normal_price: '',
        sale_price: '',
        wholesale_price: '',
        filament_weight_grams: production.filamentWeightGrams,
        printing_time_minutes: production.printingTimeMinutes,
        printer_profile_id: null,
        packaging_cost: '',
        components_cost: '',
        active_labor_minutes: null,
        stock: NEW_VARIANT.stock,
        low_stock_threshold: NEW_VARIANT.low_stock_threshold,
        is_default: false,
        active: true,
    };
}
