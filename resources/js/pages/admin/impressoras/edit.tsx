import { Head, useForm } from '@inertiajs/react';
import PrinterForm from '@/components/admin/printer-form';
import { index, update } from '@/routes/admin/impressoras';
import type {
    PrinterProfileDetail,
    PrinterProfileFormData,
} from '@/types/pricing';

type Props = {
    printer: PrinterProfileDetail;
};

export default function PrintersEdit({ printer }: Props) {
    const { data, setData, put, processing, errors } =
        useForm<PrinterProfileFormData>({
            name: printer.name,
            hourly_rate: printer.hourlyRate,
            notes: printer.notes ?? '',
            is_default: printer.isDefault,
            active: printer.active,
            sort_order: printer.sortOrder,
        });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        put(update(printer.id).url);
    };

    return (
        <>
            <Head title={`Editar ${printer.name}`} />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <h1 className="text-xl font-semibold">{printer.name}</h1>
                <PrinterForm
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

PrintersEdit.layout = {
    breadcrumbs: [
        { title: 'Backoffice', href: '/admin' },
        { title: 'Impressoras', href: index() },
        { title: 'Editar', href: '#' },
    ],
};
