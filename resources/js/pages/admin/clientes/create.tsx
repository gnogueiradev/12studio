import { Head, useForm } from '@inertiajs/react';
import CustomerForm from '@/components/admin/customer-form';
import { PageHeader } from '@/components/admin/page-header';
import { index, store } from '@/routes/admin/clientes';
import type { CustomerFormData } from '@/types/customer';
import { EMPTY_CUSTOMER_FORM } from '@/types/customer';

type Props = {
    tagSuggestions: string[];
};

export default function CustomersCreate({ tagSuggestions }: Props) {
    const { data, setData, post, processing, errors } =
        useForm<CustomerFormData>({ ...EMPTY_CUSTOMER_FORM });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        post(store().url);
    };

    return (
        <>
            <Head title="Novo cliente" />
            <div className="flex h-full w-full max-w-[1400px] flex-1 flex-col gap-4 p-6 pb-10">
                <PageHeader
                    title="Novo cliente"
                    description="O cliente não recebe email nem password — o registo serve para lhe associar encomendas. A partir da Fase 5 pode criar a sua password por recuperação."
                />
                <CustomerForm
                    data={data}
                    setData={setData}
                    errors={errors}
                    processing={processing}
                    onSubmit={submit}
                    submitLabel="Criar cliente"
                    tagSuggestions={tagSuggestions}
                />
            </div>
        </>
    );
}

CustomersCreate.layout = {
    breadcrumbs: [
        { title: 'Backoffice', href: '/admin' },
        { title: 'Clientes', href: index() },
        { title: 'Novo', href: '#' },
    ],
};
