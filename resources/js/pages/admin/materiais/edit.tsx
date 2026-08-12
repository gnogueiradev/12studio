import { Head, useForm } from '@inertiajs/react';
import MaterialForm from '@/components/admin/material-form';
import { index, update } from '@/routes/admin/materiais';
import type { MaterialDetail, MaterialFormData } from '@/types/catalog';

type Props = {
    material: MaterialDetail;
    families: string[];
};

export default function MaterialsEdit({ material, families }: Props) {
    const { data, setData, patch, processing, errors } =
        useForm<MaterialFormData>({
            name: material.name,
            family: material.family ?? '',
            supplier: material.supplier ?? '',
            price_per_kg: material.pricePerKg,
            spools_in_stock: material.spoolsInStock,
            min_spools: material.minSpools,
            active: material.active,
            sort_order: material.sortOrder,
        });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        patch(update(material.id).url);
    };

    return (
        <>
            <Head title={`Editar ${material.name}`} />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <h1 className="text-xl font-semibold">{material.name}</h1>
                <MaterialForm
                    data={data}
                    families={families}
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

MaterialsEdit.layout = {
    breadcrumbs: [
        { title: 'Backoffice', href: '/admin' },
        { title: 'Materiais', href: index() },
        { title: 'Editar', href: '#' },
    ],
};
