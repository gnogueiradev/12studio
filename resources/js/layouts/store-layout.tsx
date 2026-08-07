import { Link } from '@inertiajs/react';
import { home } from '@/routes';

/**
 * Layout da montra publica: header com navegacao, conteudo e footer com os
 * links legais (paginas legais chegam na Fase 5 — ancoras ja reservadas).
 */
export default function StoreLayout({
    children,
}: {
    children: React.ReactNode;
}) {
    return (
        <div className="flex min-h-screen flex-col bg-background text-foreground">
            <header className="sticky top-0 z-40 border-b border-border/60 bg-background/95 backdrop-blur">
                <div className="mx-auto flex h-16 w-full max-w-6xl items-center justify-between px-4">
                    <Link
                        href={home()}
                        className="text-lg font-semibold tracking-tight"
                    >
                        12studio
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
                        © {new Date().getFullYear()} 12studio — peças impressas
                        em 3D em Portugal.
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
