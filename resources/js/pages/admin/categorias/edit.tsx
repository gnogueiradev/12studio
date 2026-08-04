import { Head, useForm } from '@inertiajs/react';
import CategoryForm from '@/components/admin/category-form';
import { index, update } from '@/routes/admin/categorias';
import type { CategoryDetail, CategoryFormData } from '@/types/catalog';

type Props = {
    category: CategoryDetail;
};

export default function CategoriesEdit({ category }: Props) {
    const { data, setData, patch, processing, errors } =
        useForm<CategoryFormData>({
            name: category.name,
            description: category.description ?? '',
            active: category.active,
            sort_order: category.sortOrder,
        });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        patch(update(category.id).url);
    };

    return (
        <>
            <Head title={`Editar ${category.name}`} />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div>
                    <h1 className="text-xl font-semibold">{category.name}</h1>
                    <p className="text-sm text-muted-foreground">
                        /{category.slug}
                    </p>
                </div>
                <CategoryForm
                    data={data}
                    setData={setData}
                    errors={errors}
                    processing={processing}
                    onSubmit={submit}
                    submitLabel="Guardar alterações"
                />
            </div>
        </>
    );
}

CategoriesEdit.layout = {
    breadcrumbs: [
        { title: 'Backoffice', href: '/admin' },
        { title: 'Categorias', href: index() },
        { title: 'Editar', href: '#' },
    ],
};
