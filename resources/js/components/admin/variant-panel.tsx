import { useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import VariantForm from '@/components/admin/variant-form';
import type { VariantPricingPreview } from '@/components/admin/variant-form';
import { Button } from '@/components/ui/button';
import {
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { store as storeVariant } from '@/routes/admin/produtos/variantes';
import { update as updateVariant } from '@/routes/admin/variantes';
import type {
    ColorOption,
    MaterialOption,
    VariantFormData,
    VariantRow,
} from '@/types/catalog';
import type { PrinterProfileOption } from '@/types/pricing';

type Props = {
    productId: number;
    productName: string;
    /** Null cria; uma variante da lista edita essa variante. */
    variant: VariantRow | null;
    suggestedSku: string;
    colors: ColorOption[];
    materials: MaterialOption[];
    printers: PrinterProfileOption[];
    pricing: VariantPricingPreview;
    defaultActiveLaborMinutes: number;
    onBack: () => void;
};

/** O stock de arranque de uma variante nova, e o limiar do aviso. */
const NEW_VARIANT = {
    stock: 0,
    low_stock_threshold: 3,
} as const;

/**
 * A ficha da variante, dentro do modal do produto.
 *
 * Não é um segundo modal por cima: o modal do produto troca de face, e o
 * formulário do produto fica montado por baixo com o que o admin já lá tinha
 * escrito. Dois overlays empilhados para editar uma coisa que pertence ao
 * produto que está aberto era pedir para perder o fio.
 *
 * Não fecha nada ao gravar. O servidor responde `back()`, o Inertia remonta a
 * página, o `?editar={id}` que ficou no URL reabre o modal no mesmo produto e o
 * `variantTarget` nasce a null — ou seja, aterra-se na lista de variantes já
 * com a nova lá dentro. Em erro de validação o Inertia liga o `preserveState`
 * sozinho e a ficha fica aberta com as mensagens.
 */
export function VariantPanel({
    productId,
    productName,
    variant,
    suggestedSku,
    colors,
    materials,
    printers,
    pricing,
    defaultActiveLaborMinutes,
    onBack,
}: Props) {
    const { data, setData, post, patch, processing, errors } =
        useForm<VariantFormData>(
            variant === null
                ? {
                      sku: suggestedSku,
                      color_id: null,
                      material_id: null,
                      size_label: '',
                      normal_price: '',
                      sale_price: '',
                      wholesale_price: '',
                      filament_weight_grams: null,
                      printing_time_minutes: null,
                      printer_profile_id: null,
                      packaging_cost: '',
                      components_cost: '',
                      active_labor_minutes: null,
                      stock: NEW_VARIANT.stock,
                      low_stock_threshold: NEW_VARIANT.low_stock_threshold,
                      is_default: false,
                      active: true,
                  }
                : {
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
                  },
        );

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        // `preserveScroll` para o modal não saltar para o topo quando a
        // validação recusa: o campo que falhou pode estar a meio de um
        // formulário com esta altura.
        if (variant === null) {
            post(storeVariant(productId).url, { preserveScroll: true });

            return;
        }

        patch(updateVariant(variant.id).url, { preserveScroll: true });
    };

    return (
        <>
            <DialogHeader className="border-b border-border/60 p-6">
                <div className="flex items-center gap-2">
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="-ml-2 size-8 rounded-full"
                        onClick={onBack}
                        aria-label="Voltar ao produto"
                    >
                        <ArrowLeft className="size-4" />
                    </Button>
                    <DialogTitle>
                        {variant === null ? 'Nova variante' : variant.sku}
                    </DialogTitle>
                </div>
                <DialogDescription>
                    {productName} — cada variante tem o seu SKU, preço e stock
                    próprios.
                </DialogDescription>
            </DialogHeader>

            <div className="p-6">
                <VariantForm
                    data={data}
                    setData={setData}
                    errors={errors}
                    processing={processing}
                    onSubmit={submit}
                    submitLabel={
                        variant === null
                            ? 'Criar variante'
                            : 'Guardar alterações'
                    }
                    colors={colors}
                    materials={materials}
                    printers={printers}
                    pricing={pricing}
                    defaultActiveLaborMinutes={defaultActiveLaborMinutes}
                    reservedStock={variant?.reservedStock}
                />
            </div>
        </>
    );
}
