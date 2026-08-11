import { createInertiaApp } from '@inertiajs/react';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import SettingsLayout from '@/layouts/settings/layout';
import StoreLayout from '@/layouts/store-layout';
import { initializeCurrency } from '@/lib/money';

const appName = import.meta.env.VITE_APP_NAME || '12studio';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            // Landing "em breve" (loja fechada): desenha o ecra todo sozinha,
            // sem header nem footer da loja.
            case name === 'coming-soon':
                return undefined;
            // Montra publica (home; /produtos e /carrinho juntam-se nas
            // Fases 2-3). Tudo o resto — admin/, settings/, dashboard —
            // usa o AppLayout de sidebar.
            case name === 'home':
                return StoreLayout;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    strictMode: true,
    withApp(app) {
        return (
            <TooltipProvider delayDuration={0}>
                {app}
                <Toaster />
            </TooltipProvider>
        );
    },
    progress: {
        // Cor da marca (--foreground / --primary claro) em vez do
        // cinzento-azulado do starter kit.
        color: '#332F2B',
    },
});

// This will set light / dark mode on load...
initializeTheme();

// Moeda das definições → formatador do lib/money, antes da primeira pintura.
initializeCurrency();
