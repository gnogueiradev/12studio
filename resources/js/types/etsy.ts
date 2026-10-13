/**
 * O que os ecrãs da Etsy recebem do servidor. Os ids são os da Etsy: nada
 * disto vive na base de dados do 12studio.
 */

export type ShippingDestination = {
    id: number;
    /** Código de duas letras, ou null quando o destino é uma região. */
    country: string | null;
    /** 'eu', 'non_eu' ou 'none' (todo o lado). */
    region: string;
    primaryCostCents: number | null;
    secondaryCostCents: number | null;
    currency: string | null;
    minDays: number | null;
    maxDays: number | null;
};

export type ShippingUpgrade = {
    id: number;
    name: string;
    type: 'domestic' | 'international';
    priceCents: number | null;
    secondaryPriceCents: number | null;
    currency: string | null;
    minDays: number | null;
    maxDays: number | null;
};

export type ShippingProfile = {
    id: number;
    title: string;
    originCountry: string | null;
    originPostalCode: string | null;
    /** Anúncios ligados que usam este perfil, e quantos desses estão à venda. */
    listings: number;
    activeListings: number;
    isDefault: boolean;
    destinations: ShippingDestination[];
    upgrades: ShippingUpgrade[];
};

export type ProcessingProfile = {
    id: number;
    state: 'ready_to_ship' | 'made_to_order' | string;
    min: number | null;
    max: number | null;
    unit: 'days' | 'weeks' | string;
    activeListings: number;
    /** De que tipo de produto é a predefinição, se for de algum. */
    defaultFor: 'ready_to_ship' | 'made_to_order' | null;
};

export type ReturnPolicy = {
    id: number;
    acceptsReturns: boolean;
    acceptsExchanges: boolean;
    deadline: number | null;
    listings: number;
    isDefault: boolean;
};

export type ShippingDefaults = {
    shippingProfileId: number | null;
    readyToShipProfileId: number | null;
    madeToOrderProfileId: number | null;
    returnPolicyId: number | null;
};

export type ShippingProductOption = {
    id: number;
    name: string;
    shippingProfileId: number | null;
    /** Estado do anúncio na Etsy, ou null se o produto ainda não tem anúncio. */
    state: string | null;
};

export const DESTINATION_REGIONS = [
    { value: 'eu', label: 'União Europeia' },
    { value: 'non_eu', label: 'Fora da União Europeia' },
    { value: 'none', label: 'Todo o lado' },
] as const;

export const PROCESSING_STATES = [
    { value: 'ready_to_ship', label: 'Pronto a enviar' },
    { value: 'made_to_order', label: 'Feito por encomenda' },
] as const;

export function destinationLabel(destination: {
    country: string | null;
    region: string;
}): string {
    if (destination.country) {
        return destination.country;
    }

    return (
        DESTINATION_REGIONS.find(
            (region) => region.value === destination.region,
        )?.label ?? destination.region
    );
}

export function deliveryLabel(min: number | null, max: number | null): string {
    if (min === null || max === null) {
        return 'prazo da transportadora';
    }

    return min === max
        ? `${min} dia${min === 1 ? '' : 's'}`
        : `${min}–${max} dias`;
}

/**
 * Num diálogo que escreve na Etsy, o pedido "corre bem" para o Inertia mesmo
 * quando a Etsy recusa (volta-se à página com um aviso). Fechar aí era deitar
 * fora o que o dono escreveu; só se fecha quando não veio aviso de erro.
 */
export function etsyRefused(page: unknown): boolean {
    const flash = (page as { flash?: { toast?: { type?: string } } }).flash;

    return flash?.toast?.type === 'error';
}

const WHO_MADE_LABELS: Record<string, string> = {
    i_did: 'Fui eu',
    someone_else: 'Outra pessoa',
    collective: 'Um coletivo de que faço parte',
};

/** Quem fez a peça, como a Etsy pergunta. */
export function whoMadeLabel(value: string): string {
    return WHO_MADE_LABELS[value] ?? value;
}

/**
 * Quando foi feita. A Etsy dá os intervalos em código (`2020_2026`,
 * `before_2007`, `1990s`) e muda-os de ano a ano: traduz-se a forma, em vez
 * de ter aqui uma lista que ficava velha.
 */
export function whenMadeLabel(value: string): string {
    if (value === 'made_to_order') {
        return 'Feito por encomenda';
    }

    const range = /^(\d{4})_(\d{4})$/.exec(value);

    if (range) {
        return `${range[1]}–${range[2]}`;
    }

    const before = /^before_(\d{4})$/.exec(value);

    if (before) {
        return `Antes de ${before[1]}`;
    }

    const decade = /^(\d{4})s$/.exec(value);

    return decade ? `Anos ${decade[1]}` : value;
}

const PRICE_SOURCE_LABELS: Record<string, string> = {
    own: 'preço próprio',
    markup: 'site + margem',
    site: 'preço do site',
};

/** De onde vem o preço que a variante leva para a Etsy. */
export function priceSourceLabel(source: string): string {
    return PRICE_SOURCE_LABELS[source] ?? source;
}

const LISTING_STATE_LABELS: Record<string, string> = {
    draft: 'Rascunho',
    active: 'À venda',
    inactive: 'Inativo',
    sold_out: 'Esgotado',
    expired: 'Expirado',
    deleted: 'Apagado',
};

export function listingStateLabel(state: string | null): string {
    return state === null ? '—' : (LISTING_STATE_LABELS[state] ?? state);
}
