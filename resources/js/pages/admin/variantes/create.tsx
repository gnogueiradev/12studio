import { Head, useForm } from '@inertiajs/react';
import { PageHeader } from '@/components/admin/page-header';
import VariantForm from '@/components/admin/variant-form';
import { index } from '@/routes/admin/produtos';
import { store } from '@/routes/admin/produtos/variantes';
import type { ProductSummary, VariantFormData } from '@/types/catalog';

type Props = {
    product: ProductSummary;
    suggestedSku: string;
};

export default function VariantsCreate({ product, suggestedSku }: Props) {
    const { data, setData, post, processing, errors } =
        useForm<VariantFormData>({
            sku: suggestedSku,
            size_label: '',
            price: '',
            compare_at_price: '',
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
