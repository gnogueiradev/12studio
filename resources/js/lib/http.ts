/**
 * Pedidos JSON para os poucos sítios que não são visitas Inertia (o autosave
 * do editor, a pré-visualização, os seletores). Leva o token CSRF do cookie
 * XSRF-TOKEN, como o Laravel espera.
 */

export class HttpError extends Error {
    constructor(
        public status: number,
        public body: {
            message?: string;
            errors?: Record<string, string[]>;
        },
    ) {
        super(body.message ?? `HTTP ${status}`);
    }
}

function xsrfToken(): string | null {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : null;
}

export async function requestJson<T>(
    url: string,
    method: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE' = 'GET',
    body?: unknown,
    signal?: AbortSignal,
): Promise<T> {
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };
    const token = xsrfToken();

    if (token) {
        headers['X-XSRF-TOKEN'] = token;
    }

    let payload: BodyInit | undefined;

    if (body instanceof FormData) {
        payload = body;
    } else if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
        payload = JSON.stringify(body);
    }

    const response = await fetch(url, {
        method,
        headers,
        body: payload,
        credentials: 'same-origin',
        signal,
    });

    // Um corpo que não é JSON conta como vazio, mas cancelar a meio da leitura
    // é cancelar: não pode passar por uma resposta boa e sem nada.
    const data = await response.json().catch((error: unknown) => {
        if (signal?.aborted) {
            throw error;
        }

        return {};
    });

    if (!response.ok) {
        throw new HttpError(response.status, data);
    }

    return data as T;
}
