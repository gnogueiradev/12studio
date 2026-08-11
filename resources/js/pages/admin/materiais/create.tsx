import { Head, useForm } from '@inertiajs/react';
import MaterialForm from '@/components/admin/material-form';
import { index, store } from '@/routes/admin/materiais';
import type { MaterialFormData } from '@/types/catalog';

export default function MaterialsCreate() {
    const { data, setData, post, processing, errors } =
        useForm<MaterialFormData>({
            name: '',
            price_per_kg: '',
            active: true,
            sort_order: 0,
        });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        post(store().url);
    };

    return (
        <>
            <Head title="Novo material" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <h1 className="text-xl font-semibold">Novo material</h1>
                <MaterialForm
                    data={data}
                    setData={setData}
                    errors={errors}
                    processing={processing}
                    onSubmit={submit}
                    submitLabel="Criar material"
                />
            </div>
        </>
    );
}

MaterialsCreate.layout = {
    breadcrumbs: [
        { title: 'Backoffice', href: '/admin' },
        { title: 'Materiais', href: index() },
        { title: 'Novo', href: '#' },
    ],
};
