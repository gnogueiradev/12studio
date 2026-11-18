import { Head, useForm } from '@inertiajs/react';
import { PageHeader } from '@/components/admin/page-header';
import { PromoCodeForm } from '@/components/admin/promo-code-form';
import { index, update } from '@/routes/admin/codigos';
import type { PromoCodeDetail, PromoCodeFormData } from '@/types/promo';

type Props = {
    promoCode: PromoCodeDetail;
    channels: string[];
};

export default function PromoCodesEdit({ promoCode, channels }: Props) {
    const form = useForm<PromoCodeFormData>({
        code: promoCode.code,
        type: promoCode.type,
        value: promoCode.value,
        min_subtotal: promoCode.minSubtotal,
        starts_at: promoCode.startsAt ?? '',
        expires_at: promoCode.expiresAt ?? '',
        max_uses: promoCode.maxUses === null ? '' : String(promoCode.maxUses),
        single_use_per_email: promoCode.singleUsePerEmail,
        channel: promoCode.channel,
        email: promoCode.email ?? '',
        is_active: promoCode.isActive,
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.patch(update(promoCode.id).url);
    };

    return (
        <>
            <Head title={`Editar ${promoCode.code}`} />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title={promoCode.code}
                    description={`Usado ${promoCode.usesCount} ${promoCode.usesCount === 1 ? 'vez' : 'vezes'}.`}
                />
                <PromoCodeForm
                    form={form}
                    channels={channels}
                    onSubmit={submit}
                    submitLabel="Guardar alterações"
                />
            </div>
        </>
    );
}

PromoCodesEdit.layout = {
    breadcrumbs: [
        { title: 'Backoffice', href: '/admin' },
        { title: 'Códigos', href: index() },
        { title: 'Editar', href: '#' },
    ],
};
