/**
 * POST fora do Inertia, para as respostas que não mudam de página (contas do
 * formulário, um link para copiar). O Laravel lê o token do cookie XSRF-TOKEN.
 */
export async function postJson<T>(url: string, body: unknown = {}): Promise<T> {
    const token = document.cookie
        .split('; ')
        .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
        ?.slice('XSRF-TOKEN='.length);

    const response = await fetch(url, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': decodeURIComponent(token ?? ''),
        },
        body: JSON.stringify(body),
    });

    const json = (await response.json()) as T & { message?: string };

    if (!response.ok) {
        throw new Error(json.message ?? 'O pedido falhou.');
    }

    return json;
}
