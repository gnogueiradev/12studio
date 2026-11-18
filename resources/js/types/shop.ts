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
