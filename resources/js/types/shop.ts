/** Uma linha do carrinho (CartPresenter), já no idioma da página. */
export type CartLine = {
    id: number;
    variantId: number;
    name: string;
    label: string | null;
    href: string;
    image: string | null;
    qty: number;
    maxQty: number | null;
    unitCents: number;
    surchargeCents: number;
    lineCents: number;
    personalization: { label: string; value: string }[];
    mode: 'in_stock' | 'made_to_order' | null;
    leadDays: [number, number] | null;
    problem: 'not_for_sale' | 'custom' | 'sold_out' | 'over_stock' | null;
};

/** O carrinho feito conta (CartPresenter::make). Valores em cêntimos. */
export type CartData = {
    lines: CartLine[];
    count: number;
    subtotalCents: number;
    discountCents: number;
    shippingCents: number;
    totalCents: number;
    freeShippingLeftCents: number;
    promo: {
        code: string;
        status: 'applied' | 'pending_email' | 'refused';
        message: string | null;
    } | null;
    /** O código deixa o total abaixo de 0,50 €: não se usa na loja. */
    belowMinimum: boolean;
    canCheckout: boolean;
};

/**
 * A compra no /compra/{token} (PurchaseController). Nunca leva dados pessoais:
 * o link pode ser partilhado.
 */
export type PurchaseData = {
    state: 'confirming' | 'paid' | 'awaiting_payment' | 'expired' | 'closed';
    number: string | null;
    lines: {
        name: string;
        label: string | null;
        qty: number;
        lineCents: number;
    }[];
    subtotalCents: number;
    discountCents: number;
    shippingCents: number;
    totalCents: number;
    leadDays: [number, number] | null;
    canSaveAccount: boolean;
};

/**
 * O que o "Pagar" do /finalizar envia (StartCheckoutRequest). O país não vai:
 * é sempre Portugal. O total é só o que o cliente viu — o servidor recusa
 * cobrar outro.
 */
export type CheckoutFormData = {
    name: string;
    email: string;
    phone: string;
    nif: string;
    line1: string;
    line2: string;
    postal_code: string;
    city: string;
    terms: boolean;
    expected_total_cents: number;
};

/** O seguimento de uma encomenda; o link só vem se for http(s). */
export type AccountTracking = {
    number: string | null;
    url: string | null;
} | null;

/** A conta no /conta (CustomerAccountController::show). */
export type AccountData = {
    name: string;
    email: string;
    phone: string;
    nif: string;
    line1: string;
    line2: string;
    postalCode: string;
    city: string;
    /** O interruptor das ofertas: ligado = recebe. */
    offers: boolean;
};

/** Uma linha da lista de encomendas da conta. */
export type AccountOrderSummary = {
    number: string;
    date: string | null;
    totalCents: number;
    status: string;
    tracking: AccountTracking;
};

/** Uma encomenda da conta (CustomerAccountController::order). */
export type AccountOrder = {
    number: string;
    date: string | null;
    status: string;
    tracking: AccountTracking;
    lines: {
        name: string;
        label: string | null;
        qty: number;
        lineCents: number;
        personalization: { label: string; value: string }[];
    }[];
    address: string[] | null;
    subtotalCents: number;
    discountCents: number;
    shippingCents: number;
    totalCents: number;
    canReorder: boolean;
};
