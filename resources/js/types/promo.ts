export const PROMO_TYPES = [
    { value: 'fixed', label: 'Valor fixo (€)' },
    { value: 'percent', label: 'Percentagem (%)' },
];

export const PROMO_CHANNELS = [
    { value: 'instagram', label: 'Instagram' },
    { value: 'vinted', label: 'Vinted' },
    { value: 'referral', label: 'Recomendação' },
    { value: 'store', label: 'Loja' },
    { value: 'welcome', label: 'Boas-vindas' },
    { value: 'repeat', label: 'Voltar a comprar' },
    { value: 'other', label: 'Outro' },
];

export type PromoCodeRow = {
    id: number;
    code: string;
    type: string;
    /** Cêntimos no valor fixo, de 1 a 100 na percentagem. */
    value: number;
    channel: string;
    email: string | null;
    usesCount: number;
    maxUses: number | null;
    expiresAt: string | null;
    isActive: boolean;
};

export type PromoCodeDetail = {
    id: number;
    code: string;
    type: string;
    value: string;
    minSubtotal: string;
    startsAt: string | null;
    expiresAt: string | null;
    maxUses: number | null;
    usesCount: number;
    singleUsePerEmail: boolean;
    channel: string;
    email: string | null;
    isActive: boolean;
};

export type PromoCodeFormData = {
    code: string;
    type: string;
    value: string;
    min_subtotal: string;
    starts_at: string;
    expires_at: string;
    max_uses: string;
    single_use_per_email: boolean;
    channel: string;
    email: string;
    is_active: boolean;
};
