import { Head, useForm } from '@inertiajs/react';
import { PageHeader } from '@/components/admin/page-header';
import VariantForm from '@/components/admin/variant-form';
import type { VariantPricingPreview } from '@/components/admin/variant-form';
import { index } from '@/routes/admin/produtos';
import { store } from '@/routes/admin/produtos/variantes';
import type {
    ColorGroup,
    ProductSummary,
    VariantFormData,
} from '@/types/catalog';
import type { PrinterProfileOption } from '@/types/pricing';

type Props = {
    product: ProductSummary;
    suggestedSku: string;
    colorGroups: ColorGroup[];
    printers: PrinterProfileOption[];
    pricing: VariantPricingPreview;
};

export default function VariantsCreate({
    product,
    suggestedSku,
    colorGroups,
    printers,
    pricing,
}: Props) {
    const { data, setData, post, processing, errors } =
        useForm<VariantFormData>({
            sku: suggestedSku,
            color_id: null,
            size_label: '',
            normal_price: '',
            sale_price: '',
            wholesale_price: '',
            filament_weight_grams: null,
            printing_time_minutes: null,
            printer_profile_id: null,
            extra_cost: '',
            stock: 0,
            low_stock_threshold: 3,
            is_default: false,
            active: true,
        });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        post(store(product.id).url);
    };

    return (
        <>
            <Head title={`Nova variante · ${product.name}`} />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Nova variante"
                    description={`${product.name} — cada variante tem o seu SKU, preço e stock próprios.`}
                />
                <VariantForm
                    data={data}
                    setData={setData}
                    errors={errors}
                    processing={processing}
                    onSubmit={submit}
                    submitLabel="Criar variante"
                    colorGroups={colorGroups}
                    printers={printers}
                    pricing={pricing}
                />
            </div>
        </>
    );
}

VariantsCreate.layout = {
    breadcrumbs: [
        { title: 'Backoffice', href: '/admin' },
        { title: 'Produtos', href: index() },
        { title: 'Nova variante', href: '#' },
    ],
};
