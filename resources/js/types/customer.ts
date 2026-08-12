/**
 * Particular ou empresa. Muda o label do nome no formulário e torna o NIF
 * obrigatório — não há faturação a empresa sem ele.
 */
export const CUSTOMER_TYPES = [
    { value: 'particular', label: 'Particular' },
    { value: 'empresa', label: 'Empresa' },
] as const;

export type CustomerRow = {
    id: number;
    name: string;
    /** Vazio quando o cliente foi criado só com o nome. */
    email: string | null;
    customerType: string;
    nif: string | null;
    /** Canal mais frequente nas encomendas; null sem histórico. */
    habitualChannel: string | null;
    ordersCount: number;
    /** Só encomendas com payment_status = paid. */
    paidTotalCents: number;
    lastOrderAt: string | null;
    /** "09 ago", já formatado em PT pelo servidor. */
    lastOrderAtShort: string | null;
    createdAt: string | null;
};

export type CustomerDetail = {
    id: number;
    name: string;
    email: string | null;
    customerType: string;
    phone: string | null;
    nif: string | null;
    adminNote: string | null;
    line1: string | null;
    line2: string | null;
    postalCode: string | null;
    city: string | null;
    country: string;
    createdAt: string | null;
    /** Falso assim que existe uma encomenda (regra global de eliminação). */
    canDelete: boolean;
};

export type CustomerFormData = {
    name: string;
    email: string;
    customer_type: string;
    phone: string;
    nif: string;
    admin_note: string;
    line1: string;
    line2: string;
    postal_code: string;
    city: string;
    country: string;
};

/** Campos do formulário de cliente vazios — o ponto de partida do modal. */
export const EMPTY_CUSTOMER_FORM: CustomerFormData = {
    name: '',
    email: '',
    customer_type: 'particular',
    phone: '',
    nif: '',
    admin_note: '',
    line1: '',
    line2: '',
    postal_code: '',
    city: '',
    country: 'PT',
};

/**
 * O nome já chega válido pelo servidor; isto é só para o botão não estar ativo
 * a prometer uma gravação que a validação vai recusar. A regra é a mesma do
 * `StoreCustomerRequest`: nome sempre, NIF de 9 dígitos quando é empresa.
 */
export function isCustomerFormReady(data: CustomerFormData): boolean {
    return (
        data.name.trim() !== '' &&
        (data.customer_type !== 'empresa' ||
            data.nif.replace(/\D/g, '').length === 9)
    );
}

/** "Ana Marques" → "AM". Duas iniciais chegam para distinguir um avatar. */
export function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase() ?? '')
        .join('');
}

/** Cliente escolhível numa encomenda manual. */
export type CustomerOption = {
    id: number;
    name: string;
    email: string | null;
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
