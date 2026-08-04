import { Head, useForm } from '@inertiajs/react';
import CategoryForm from '@/components/admin/category-form';
import { index, store } from '@/routes/admin/categorias';
import type { CategoryFormData } from '@/types/catalog';

export default function CategoriesCreate() {
    const { data, setData, post, processing, errors } =
        useForm<CategoryFormData>({
            name: '',
            description: '',
            active: true,
            sort_order: 0,
        });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        post(store().url);
    };

    return (
        <>
            <Head title="Nova categoria" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <h1 className="text-xl font-semibold">Nova categoria</h1>
                <CategoryForm
                    data={data}
                    setData={setData}
                    errors={errors}
                    processing={processing}
                    onSubmit={submit}
                    submitLabel="Criar categoria"
                />
            </div>
        </>
    );
}

CategoriesCreate.layout = {
    breadcrumbs: [
        { title: 'Backoffice', href: '/admin' },
        { title: 'Categorias', href: index() },
        { title: 'Nova', href: '#' },
    ],
};
