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

/**
 * Casas do cálculo detalhado. Cinco porque é o que a fórmula produz: uma
 * reserva de falha de 0,10352 € e um custo de 1,64752 € são os números que o
 * servidor calcula, e mostrar 0,10 € nesse painel escondia precisamente o que
 * ele existe para explicar.
 */
const MICRO_FRACTION_DIGITS = 5;

const percentFormatter = new Intl.NumberFormat('pt-PT', {
    style: 'percent',
    minimumFractionDigits: 1,
    maximumFractionDigits: 1,
});

const multiplierFormatter = new Intl.NumberFormat('pt-PT', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

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

/**
 * Cêntimos numa moeda à escolha, independente da que a loja tem em vigor.
 *
 * Existe para a pré-visualização da página de definições, que tem de mostrar
 * como fica um preço na moeda nova ANTES de alguém a guardar — coisa que o
 * formatador de módulo, preso à moeda em vigor, não sabe fazer.
 *
 * Reaproveita esse formatador quando o código pedido já é o corrente: no resto
 * do backoffice é sempre esse o caso, e construir um Intl.NumberFormat por cada
 * preço de uma tabela de variantes sairia caro por nada.
 */
export function formatCentsIn(cents: number, code: string): string {
    const chosen =
        code === currency
            ? formatter
            : new Intl.NumberFormat('pt-PT', {
                  style: 'currency',
                  currency: code,
              });

    return chosen.format(cents / 100);
}

/** Cêntimos inteiros → "12,50 €". Nunca guardamos floats. */
export function formatCents(cents: number): string {
    return formatCentsIn(cents, currency);
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

/**
 * Micro-euros → string com a precisão que o valor tiver ("544000" → "0,544 €").
 *
 * O cálculo de custo corre em micros (1/1 000 000 €) para não arredondar as
 * parcelas intermédias — 0,10352 € de reserva de falha não cabe num cêntimo.
 * É esse detalhe que o painel "Ver cálculo detalhado" mostra, e é a única
 * razão para este formatador existir: em todo o resto do backoffice o cêntimo
 * chega e o `formatCents` é o certo.
 *
 * `maximumFractionDigits` sem mínimo: 0,75 € mostra-se com duas casas e
 * 0,10352 € com cinco, em vez de encher tudo de zeros à direita.
 */
export function formatMicros(micros: number): string {
    return new Intl.NumberFormat('pt-PT', {
        style: 'currency',
        currency,
        minimumFractionDigits: 2,
        maximumFractionDigits: MICRO_FRACTION_DIGITS,
    }).format(micros / 1_000_000);
}

/**
 * Pontos base → percentagem legível ("5293" → "52,9 %").
 *
 * As margens e os multiplicadores viajam em bp (10 000 = 100 %) para o servidor
 * nunca ter de mandar um float. Uma casa decimal chega: a diferença entre
 * 52,93 % e 52,9 % de margem não muda decisão nenhuma.
 */
export function formatPercentBp(bp: number): string {
    return percentFormatter.format(bp / 10_000);
}

/** Pontos base → multiplicador ("20000" → "2,00×"). */
export function formatMultiplierBp(bp: number): string {
    return `${multiplierFormatter.format(bp / 10_000)}×`;
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
