import { Head } from '@inertiajs/react';

/**
 * Landing enquanto a loja esta fechada (config/access.php -> store_open).
 * Autonoma de proposito: sem StoreLayout, porque um header com um unico link
 * e um rodape de paginas legais que ainda nao existem so fazem ruido aqui.
 */
export default function ComingSoon() {
    return (
        <>
            <Head title="Em breve" />

            <div className="flex min-h-screen flex-col bg-background text-foreground">
                <main className="flex flex-1 items-center justify-center px-6 py-20">
                    <div className="w-full max-w-xl">
                        <p className="text-sm font-medium tracking-[0.2em] text-muted-foreground uppercase">
                            12studio
                        </p>

                        <h1 className="mt-6 text-4xl font-semibold tracking-tight text-balance sm:text-5xl">
                            Peças impressas em 3D, feitas em Portugal.
                        </h1>

                        <p className="mt-5 text-lg text-pretty text-muted-foreground">
                            Decoração, gadgets e peças personalizadas —
                            desenhadas e impressas peça a peça no nosso estúdio.
                        </p>

                        <div className="mt-10 flex items-center gap-3 border-t border-border pt-6">
                            <span
                                aria-hidden="true"
                                className="size-2 shrink-0 rounded-full bg-foreground/40"
                            />
                            <p className="text-sm text-muted-foreground">
                                A loja abre em breve.
                            </p>
                        </div>
                    </div>
                </main>

                <footer className="px-6 pb-8">
                    <p className="mx-auto w-full max-w-xl text-sm text-muted-foreground">
                        © {new Date().getFullYear()} 12studio
                    </p>
                </footer>
            </div>
        </>
    );
}
