const formatter = new Intl.NumberFormat('pt-PT', {
    style: 'currency',
    currency: 'EUR',
});

/** Cêntimos inteiros → "12,50 €". Nunca guardamos floats. */
export function formatCents(cents: number): string {
    return formatter.format(cents / 100);
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
