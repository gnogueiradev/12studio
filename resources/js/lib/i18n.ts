import { usePage } from '@inertiajs/react';
import { useCallback, useMemo } from 'react';

/**
 * Idiomas da montra pública. Tudo vem das props da página (SetStoreLocale) e
 * nada fica em estado de módulo: o SSR serve pedidos de idiomas diferentes no
 * mesmo processo, e uma variável global passava o idioma de um visitante para
 * o seguinte.
 *
 * O backoffice não usa isto — fica em português e não recebe estas props.
 */

export type StoreLocale = {
    code: string;
    name: string;
    /** Localização do Intl (preços, datas): "pt-PT", "en-IE"… */
    intl: string;
};

export type LocaleProps = {
    current: string;
    default: string;
    available: StoreLocale[];
};

type Messages = { [key: string]: string | Messages };

type Replacements = Record<string, string | number>;

const EMPTY: Messages = {};

const FALLBACK: LocaleProps = {
    current: 'pt',
    default: 'pt',
    available: [{ code: 'pt', name: 'Português', intl: 'pt-PT' }],
};

/** O texto na chave com pontos ("nav.catalog"), ou null se não existir. */
function lookup(messages: Messages, key: string): string | null {
    let node: string | Messages | undefined = messages;

    for (const part of key.split('.')) {
        if (typeof node !== 'object' || node === null) {
            return null;
        }

        node = node[part];
    }

    return typeof node === 'string' ? node : null;
}

/** ":name" → valor, como o __() do Laravel. As chaves mais longas primeiro. */
function replace(text: string, replacements: Replacements): string {
    return Object.keys(replacements)
        .sort((a, b) => b.length - a.length)
        .reduce(
            (result, key) =>
                result.replaceAll(`:${key}`, String(replacements[key])),
            text,
        );
}

/**
 * A forma plural certa de "{1} … hora.|[2,*] … horas." — o mesmo formato do
 * trans_choice do Laravel, para as mesmas chaves servirem nos dois lados.
 * Segmentos sem intervalo: o primeiro para 1, o segundo para o resto.
 */
export function choose(text: string, count: number): string {
    const segments = text.split('|');
    const plain: string[] = [];

    for (const segment of segments) {
        const exact = segment.match(/^\s*\{(\d+)\}\s*(.*)$/s);

        if (exact) {
            if (Number(exact[1]) === count) {
                return exact[2];
            }

            continue;
        }

        const range = segment.match(/^\s*\[(\d+|\*),\s*(\d+|\*)\]\s*(.*)$/s);

        if (range) {
            const min = range[1] === '*' ? -Infinity : Number(range[1]);
            const max = range[2] === '*' ? Infinity : Number(range[2]);

            if (count >= min && count <= max) {
                return range[3];
            }

            continue;
        }

        plain.push(segment);
    }

    if (plain.length === 0) {
        return segments[segments.length - 1];
    }

    return count === 1 || plain.length === 1 ? plain[0] : plain[1];
}

export function useLocale(): LocaleProps & { intl: string } {
    const locale =
        (usePage().props.locale as LocaleProps | undefined) ?? FALLBACK;

    const intl =
        locale.available.find((item) => item.code === locale.current)?.intl ??
        'pt-PT';

    return { ...locale, intl };
}

/**
 * `t('nav.catalog')` e `t.choice('contact.response_time', 24)`. Uma chave em
 * falta mostra-se tal como está — vê-se logo no ecrã, e o
 * StoreTranslationsTest apanha-a antes de lá chegar.
 */
export function useT() {
    const raw = usePage().props.i18n;
    const messages = useMemo(
        () => (raw as Messages | undefined) ?? EMPTY,
        [raw],
    );

    const t = useCallback(
        (key: string, replacements: Replacements = {}): string =>
            replace(lookup(messages, key) ?? key, replacements),
        [messages],
    );

    return useMemo(
        () =>
            Object.assign(t, {
                choice: (
                    key: string,
                    count: number,
                    replacements: Replacements = {},
                ): string =>
                    replace(choose(lookup(messages, key) ?? key, count), {
                        count,
                        ...replacements,
                    }),
            }),
        [t, messages],
    );
}

/** Tira o prefixo de idioma de um caminho: "/en/produtos" → "/produtos". */
export function stripLocale(path: string, locale: LocaleProps): string {
    for (const { code } of locale.available) {
        if (code === locale.default) {
            continue;
        }

        if (path === `/${code}`) {
            return '/';
        }

        if (path.startsWith(`/${code}/`) || path.startsWith(`/${code}?`)) {
            return path.slice(code.length + 1);
        }
    }

    return path;
}

/**
 * Um caminho da montra no idioma pedido. Os helpers do Wayfinder geram sempre
 * o caminho do idioma por omissão (`home().url` → "/"); isto põe-lhe o prefixo.
 * Só caminhos internos: um URL absoluto passa tal como veio.
 */
export function localizedUrl(
    url: string,
    code: string,
    locale: LocaleProps,
): string {
    if (!url.startsWith('/')) {
        return url;
    }

    const path = stripLocale(url, locale);

    if (code === locale.default) {
        return path;
    }

    return path === '/' ? `/${code}` : `/${code}${path}`;
}

/** `const url = useLocalizedUrl(); url(home().url)` — no idioma da página. */
export function useLocalizedUrl() {
    const locale = useLocale();

    return useCallback(
        (url: string, code: string = locale.current): string =>
            localizedUrl(url, code, locale),
        [locale],
    );
}
