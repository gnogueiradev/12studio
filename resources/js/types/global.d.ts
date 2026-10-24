import type { LocaleProps } from '@/lib/i18n';
import type { Auth } from '@/types/auth';

declare module 'react' {
    // eslint-disable-next-line @typescript-eslint/no-unused-vars
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            /** Nome da loja e frase do rodapé, das definições. */
            brand: { name: string; tagline: string };
            auth: Auth;
            sidebarOpen: boolean;
            /** Código ISO vindo das definições (EUR por omissão). */
            currency: string;
            /** Só nas páginas da montra: true quando está fechada e é o admin a ver. */
            storePreview?: boolean;
            /** Só nas páginas da montra (SetStoreLocale). */
            locale?: LocaleProps;
            [key: string]: unknown;
        };
    }
}
