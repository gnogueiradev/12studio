/**
 * O utilizador autenticado tal como o HandleInertiaRequests o partilha — e nada
 * mais. Nao e o modelo do servidor: o `phone`, o `nif`, o `admin_note` e o
 * `is_admin` ficam de fora de proposito, porque isto vai no HTML de todas as
 * paginas.
 *
 * Sem indice `[key: string]: unknown`, tambem de proposito: com ele o
 * `types:check` deixava passar a leitura de campos que o servidor nunca envia,
 * e o valor so aparecia como `undefined` em runtime.
 */
export type User = {
    id: number;
    name: string;
    email: string;
    email_verified_at: string | null;
};

export type Auth = {
    user: User;
};

/* @chisel-passkeys */
export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    created_at_diff: string;
    last_used_at_diff: string | null;
};
/* @end-chisel-passkeys */

export type TwoFactorSetupData = {
    svg: string;
    url: string;
};

export type TwoFactorSecretKey = {
    secretKey: string;
};
