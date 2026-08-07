export type CustomerRow = {
    id: number;
    name: string;
    email: string;
    city: string | null;
    ordersCount: number;
    /** Só encomendas com payment_status = paid. */
    paidTotalCents: number;
    createdAt: string | null;
};

export type CustomerDetail = {
    id: number;
    name: string;
    email: string;
    line1: string | null;
    line2: string | null;
    postalCode: string | null;
    city: string | null;
    country: string;
    phone: string | null;
    nif: string | null;
    createdAt: string | null;
    /** Falso assim que existe uma encomenda (regra global de eliminação). */
    canDelete: boolean;
};

export type CustomerFormData = {
    name: string;
    email: string;
    line1: string;
    line2: string;
    postal_code: string;
    city: string;
    country: string;
    phone: string;
    nif: string;
};

/** Cliente escolhível numa encomenda manual. */
export type CustomerOption = {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    nif: string | null;
    address: {
        line1: string;
        line2: string | null;
        postalCode: string;
        city: string;
        country: string;
    } | null;
};
