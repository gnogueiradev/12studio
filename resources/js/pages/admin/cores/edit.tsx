import { Head, useForm } from '@inertiajs/react';
import ColorForm from '@/components/admin/color-form';
import { index, update } from '@/routes/admin/cores';
import type {
    ColorDetail,
    ColorFormData,
    MaterialOption,
} from '@/types/catalog';

type Props = {
    color: ColorDetail;
    materials: MaterialOption[];
};

export default function ColorsEdit({ color, materials }: Props) {
    const { data, setData, patch, processing, errors } = useForm<ColorFormData>(
        {
            material_id: color.materialId,
            name: color.name,
            hex_color: color.hexColor,
            price_per_kg: color.pricePerKg ?? '',
            is_active: color.isActive,
            sort_order: color.sortOrder,
        },
    );

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        patch(update(color.id).url);
    };

    return (
        <>
            <Head title={`Editar ${color.name}`} />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <h1 className="text-xl font-semibold">{color.name}</h1>
                <ColorForm
                    data={data}
                    setData={setData}
                    errors={errors}
                    processing={processing}
                    onSubmit={submit}
                    submitLabel="Guardar alterações"
                    materials={materials}
                />
            </div>
        </>
    );
}

ColorsEdit.layout = {
    breadcrumbs: [
        { title: 'Backoffice', href: '/admin' },
        { title: 'Cores', href: index() },
        { title: 'Editar', href: '#' },
    ],
};
