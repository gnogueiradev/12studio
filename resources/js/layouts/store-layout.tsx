import { Link, usePage } from '@inertiajs/react';
import { EyeOff } from 'lucide-react';
import { home } from '@/routes';
import { index as storeSettings } from '@/routes/admin/montra';

/**
 * Layout da montra publica: header com navegacao, conteudo e footer com os
 * links legais (paginas legais chegam na Fase 5 — ancoras ja reservadas).
 */
export default function StoreLayout({
    children,
}: {
    children: React.ReactNode;
}) {
    const { brand, storePreview } = usePage().props;

    return (
        <div className="flex min-h-screen flex-col bg-background text-foreground">
            {storePreview && (
                <div
                    role="status"
                    className="flex flex-wrap items-center justify-center gap-x-3 gap-y-1 bg-foreground px-4 py-2 text-center text-sm text-background"
                >
                    <EyeOff aria-hidden="true" className="size-4" />
                    <span>
                        Montra fechada — só tu vês isto. Os visitantes veem a
                        página “em breve”.
                    </span>
                    <Link
                        href={storeSettings()}
                        className="font-medium underline underline-offset-4"
                    >
                        Abrir a montra
                    </Link>
                </div>
            )}
            <header className="sticky top-0 z-40 border-b border-border/60 bg-background/95 backdrop-blur">
                <div className="mx-auto flex h-16 w-full max-w-6xl items-center justify-between px-4">
                    <Link
                        href={home()}
                        className="text-lg font-semibold tracking-tight"
                    >
                        {brand.name}
                    </Link>

                    <nav className="flex items-center gap-6 text-sm">
                        <Link
                            href={home()}
                            className="text-muted-foreground transition-colors hover:text-foreground"
                        >
                            Início
                        </Link>
                        {/* Sem link "Entrar": o /login esta escondido atras do
                            URL secreto (config/access.php). O link de conta de
                            cliente volta na Fase 5. */}
                    </nav>
                </div>
            </header>

            <main className="flex-1">{children}</main>

            <footer className="border-t border-border/60">
                <div className="mx-auto flex w-full max-w-6xl flex-col gap-2 px-4 py-8 text-sm text-muted-foreground md:flex-row md:items-center md:justify-between">
                    <p>
                        © {new Date().getFullYear()} {brand.name}
                        {brand.tagline && ` — ${brand.tagline}`}
                    </p>
                    <nav className="flex flex-wrap gap-4">
                        {/* Paginas legais entram na Fase 5 (lancamento). */}
                        <span>Termos</span>
                        <span>Privacidade</span>
                        <span>Envios e devoluções</span>
                    </nav>
                </div>
            </footer>
        </div>
    );
}
