import { Head, useForm } from '@inertiajs/react';
import { PageHeader } from '@/components/admin/page-header';
import VariantForm from '@/components/admin/variant-form';
import type { VariantPricingPreview } from '@/components/admin/variant-form';
import { index } from '@/routes/admin/produtos';
import { update } from '@/routes/admin/variantes';
import type {
    ColorOption,
    MaterialOption,
    ProductSummary,
    VariantDetail,
    VariantFormData,
} from '@/types/catalog';
import type { PrinterProfileOption } from '@/types/pricing';

type Props = {
    product: ProductSummary;
    variant: VariantDetail;
    colors: ColorOption[];
    materials: MaterialOption[];
    printers: PrinterProfileOption[];
    pricing: VariantPricingPreview;
};

export default function VariantsEdit({
    product,
    variant,
    colors,
    materials,
    printers,
    pricing,
}: Props) {
    const { data, setData, patch, processing, errors } =
        useForm<VariantFormData>({
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
            extra_cost: variant.extraCost ?? '',
            stock: variant.stock,
            low_stock_threshold: variant.lowStockThreshold,
            is_default: variant.isDefault,
            active: variant.active,
        });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        patch(update(variant.id).url);
    };

    return (
        <>
            <Head title={`${variant.sku} · ${product.name}`} />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <PageHeader title={variant.sku} description={product.name} />
                <VariantForm
                    data={data}
                    setData={setData}
                    errors={errors}
                    processing={processing}
                    onSubmit={submit}
                    submitLabel="Guardar alterações"
                    colors={colors}
                    materials={materials}
                    printers={printers}
                    pricing={pricing}
                    reservedStock={variant.reservedStock}
                />
            </div>
        </>
    );
}

VariantsEdit.layout = {
    breadcrumbs: [
        { title: 'Backoffice', href: '/admin' },
        { title: 'Produtos', href: index() },
        { title: 'Variante', href: '#' },
    ],
};
