import { Head, useForm } from '@inertiajs/react';
import { PageHeader } from '@/components/admin/page-header';
import { PromoCodeForm } from '@/components/admin/promo-code-form';
import { index, store } from '@/routes/admin/codigos';
import type { PromoCodeFormData } from '@/types/promo';

type Props = {
    channels: string[];
};

export default function PromoCodesCreate({ channels }: Props) {
    const form = useForm<PromoCodeFormData>({
        code: '',
        type: 'fixed',
        value: '',
        min_subtotal: '0',
        starts_at: '',
        expires_at: '',
        max_uses: '',
        single_use_per_email: false,
        channel: 'instagram',
        email: '',
        is_active: true,
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.post(store().url);
    };

    return (
        <>
            <Head title="Novo código" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <PageHeader title="Novo código" />
                <PromoCodeForm
                    form={form}
                    channels={channels}
                    onSubmit={submit}
                    submitLabel="Criar código"
                />
            </div>
        </>
    );
}

PromoCodesCreate.layout = {
    breadcrumbs: [
        { title: 'Backoffice', href: '/admin' },
        { title: 'Códigos', href: index() },
        { title: 'Novo', href: '#' },
    ],
};
