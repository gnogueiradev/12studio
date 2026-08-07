import { Head, useForm } from '@inertiajs/react';
import { PageHeader } from '@/components/admin/page-header';
import VariantForm from '@/components/admin/variant-form';
import { index } from '@/routes/admin/produtos';
import { update } from '@/routes/admin/variantes';
import type {
    ProductSummary,
    VariantDetail,
    VariantFormData,
} from '@/types/catalog';

type Props = {
    product: ProductSummary;
    variant: VariantDetail;
};

export default function VariantsEdit({ product, variant }: Props) {
    const { data, setData, patch, processing, errors } =
        useForm<VariantFormData>({
            sku: variant.sku,
            size_label: variant.sizeLabel ?? '',
            price: variant.price,
            compare_at_price: variant.compareAtPrice ?? '',
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
