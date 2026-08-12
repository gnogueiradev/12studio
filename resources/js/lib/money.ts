import { getInitialPageFromDOM, router } from '@inertiajs/core';
import type { Page } from '@inertiajs/core';

/** O `id` por omissão do createInertiaApp — o app.tsx não lhe mexe. */
const INERTIA_APP_ID = 'app';

const DEFAULT_CURRENCY = 'EUR';

/**
 * Casas decimais do custo por grama. Um quilo a 21,90 € dá 0,022 € por grama:
 * com as duas casas habituais era tudo "0,02 €" e a coluna deixava de
 * distinguir um PLA de um TPU.
 */
const GRAM_FRACTION_DIGITS = 3;

let currency = DEFAULT_CURRENCY;
let formatter = new Intl.NumberFormat('pt-PT', {
    style: 'currency',
    currency,
});
let gramFormatter = new Intl.NumberFormat('pt-PT', {
    style: 'currency',
    currency,
    minimumFractionDigits: GRAM_FRACTION_DIGITS,
    maximumFractionDigits: GRAM_FRACTION_DIGITS,
});

function setCurrency(code: unknown): void {
    if (typeof code !== 'string' || code === '' || code === currency) {
        return;
    }

    currency = code;
    formatter = new Intl.NumberFormat('pt-PT', {
        style: 'currency',
        currency: code,
    });
    gramFormatter = new Intl.NumberFormat('pt-PT', {
        style: 'currency',
        currency: code,
        minimumFractionDigits: GRAM_FRACTION_DIGITS,
        maximumFractionDigits: GRAM_FRACTION_DIGITS,
    });
}

/**
 * A moeda vem das definições (tabela `settings`), partilhada em todas as
 * páginas pelo HandleInertiaRequests.
 *
 * Fica em estado de módulo e não num contexto de React para o formatCents
 * manter a assinatura de sempre — as dezenas de chamadas existentes não
 * precisam de saber que isto mudou. Chamado no arranque da app, ao lado do
 * initializeTheme.
 *
 * A leitura inicial vem do DOM porque tem de estar feita antes da primeira
 * pintura; o evento `navigate` trata das visitas seguintes (a moeda pode
 * mudar a meio da sessão, na página de definições).
 */
export function initializeCurrency(): void {
    // Devolve null sem o <script data-page> (testes, render isolado); nesse
    // caso fica o valor por omissão — uma moeda não justifica rebentar o
    // arranque da aplicação.
    const initial = getInitialPageFromDOM<Page>(INERTIA_APP_ID);

    setCurrency(initial?.props.currency);

    router.on('navigate', (event) =>
        setCurrency(event.detail.page.props.currency),
    );
}

/** Cêntimos inteiros → "12,50 €". Nunca guardamos floats. */
export function formatCents(cents: number): string {
    return formatter.format(cents / 100);
}

/**
 * Preço por quilo em cêntimos → custo de um grama ("0,022 €").
 *
 * Um quilo são mil gramas, e o preço vem em cêntimos: daí o /100 para euros e
 * o /1000 para o grama. É o número que liga o preço do rolo ao custo da peça —
 * uma peça de 100 g custa cem vezes isto.
 */
export function formatCostPerGram(pricePerKgCents: number): string {
    return gramFormatter.format(pricePerKgCents / 100 / 1000);
}

/** Cêntimos → string para inputs de texto ("1250" → "12.50"). */
export function centsToInput(cents: number | null): string {
    return cents === null ? '' : (cents / 100).toFixed(2);
}

/**
 * Euros escritos à mão → cêntimos. Aceita vírgula ou ponto decimal.
 * Espelha App\Support\Money::fromDecimal, mas o servidor é a autoridade —
 * isto serve só para o total ao vivo no formulário.
 */
export function inputToCents(value: string): number {
    const clean = value.replace(/[^0-9,.-]/g, '').replace(',', '.');
    const parsed = Number.parseFloat(clean);

    return Number.isNaN(parsed) ? 0 : Math.round(parsed * 100);
}
