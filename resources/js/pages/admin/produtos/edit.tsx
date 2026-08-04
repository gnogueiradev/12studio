import { Head, useForm } from '@inertiajs/react';
import ProductForm from '@/components/admin/product-form';
import { index, update } from '@/routes/admin/produtos';
import type {
    CategoryOption,
    ProductDetail,
    ProductFormData,
} from '@/types/catalog';

type Props = {
    product: ProductDetail;
    categories: CategoryOption[];
};

export default function ProductsEdit({ product, categories }: Props) {
    const { data, setData, patch, processing, errors } =
        useForm<ProductFormData>({
            name: product.name,
            category_id: product.categoryId,
            description: product.description ?? '',
            status: product.status,
            featured: product.featured,
            vat_rate: product.vatRate,
            fulfillment_mode: product.fulfillmentMode,
            production_time_days: product.productionTimeDays,
            allow_backorder: product.allowBackorder,
            max_open_production_qty: product.maxOpenProductionQty,
        });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        patch(update(product.id).url);
    };

    return (
        <>
            <Head title={`Editar ${product.name}`} />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div>
                    <h1 className="text-xl font-semibold">{product.name}</h1>
                    <p className="text-sm text-muted-foreground">
                        /{product.slug}
                    </p>
                </div>
                <ProductForm
                    data={data}
                    setData={setData}
                    errors={errors}
                    processing={processing}
                    onSubmit={submit}
                    submitLabel="Guardar alterações"
                    categories={categories}
                />
            </div>
        </>
    );
}

ProductsEdit.layout = {
    breadcrumbs: [
        { title: 'Backoffice', href: '/admin' },
        { title: 'Produtos', href: index() },
        { title: 'Editar', href: '#' },
    ],
};
