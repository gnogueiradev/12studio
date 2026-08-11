import { Head, useForm } from '@inertiajs/react';
import ProductForm from '@/components/admin/product-form';
import { index, store } from '@/routes/admin/produtos';
import type { CategoryOption, ProductFormData } from '@/types/catalog';

type Props = {
    categories: CategoryOption[];
    defaultVatRate: number;
    tagSuggestions: string[];
};

export default function ProductsCreate({
    categories,
    defaultVatRate,
    tagSuggestions,
}: Props) {
    const { data, setData, post, processing, errors } =
        useForm<ProductFormData>({
            name: '',
            slug: '',
            category_id: null,
            description: '',
            tags: [],
            status: 'draft',
            featured: false,
            vat_rate: defaultVatRate,
            fulfillment_mode: 'in_stock',
            production_time_days: null,
            allow_backorder: false,
            max_open_production_qty: null,
        });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        post(store().url);
    };

    return (
        <>
            <Head title="Novo produto" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <h1 className="text-xl font-semibold">Novo produto</h1>
                <p className="text-sm text-muted-foreground">
                    Guarda o produto para lhe poderes juntar fotografias e
                    variantes — ambas precisam que ele já exista.
                </p>
                <ProductForm
                    data={data}
                    setData={setData}
                    errors={errors}
                    processing={processing}
                    onSubmit={submit}
                    submitLabel="Criar produto"
                    categories={categories}
                    tagSuggestions={tagSuggestions}
                />
            </div>
        </>
    );
}

ProductsCreate.layout = {
    breadcrumbs: [
        { title: 'Backoffice', href: '/admin' },
        { title: 'Produtos', href: index() },
        { title: 'Novo', href: '#' },
    ],
};
