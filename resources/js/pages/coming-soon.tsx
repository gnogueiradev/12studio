import { Form, Head } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import AppLogoIcon from '@/components/app-logo-icon';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { notify } from '@/routes';

/**
 * Landing enquanto a loja esta fechada (config/access.php -> store_open).
 *
 * Autonoma de proposito: sem StoreLayout, porque um header com um unico link
 * e um rodape de paginas legais que ainda nao existem so fazem ruido aqui.
 *
 * Escura para toda a gente, independentemente do tema do visitante — e por
 * isso que a raiz leva `dark`. A paleta do desenho (#211E1C, #C6A77B, #A99582)
 * e exactamente o bloco `.dark` do app.css, por isso nao ha aqui uma unica cor
 * literal: dentro deste escopo os tokens da marca ja resolvem nos valores
 * certos, e o teste-guarda de tests/Unit/DesignTokensTest.php continua limpo.
 */

/**
 * A entrada dos blocos (subir 16px + fade) vem do tw-animate-css. So o
 * `animate-in` fica sob `motion-safe:`: as outras classes so escrevem
 * variaveis e, sem animacao, o elemento renderiza no estado final — que e
 * exactamente o que quem pede prefers-reduced-motion deve ver.
 *
 * `fill-mode-both` e obrigatorio nos blocos com atraso: o `--animate-in` do
 * tw-animate-css usa fill-mode `none` por omissao, e sem isto o bloco pisca
 * visivel antes de o atraso acabar e saltar para opacidade zero.
 */
const RISE_IN =
    'ease-[cubic-bezier(0.2,0.7,0.2,1)] fill-mode-both motion-safe:animate-in motion-safe:fade-in motion-safe:slide-in-from-bottom-4';

export default function ComingSoon() {
    return (
        <>
            <Head title="Em breve" />

            <div className="dark">
                {/* O `bg-background` vive no filho e nao no no com `dark`:
                    o @custom-variant do app.css e `&:is(.dark *)`, que apanha
                    descendentes e nao o proprio no. O `overscroll-none` impede
                    que o rubber-band do telemovel mostre o bege que o Blade
                    pinta no <html> para quem tem tema claro. */}
                <div className="relative flex min-h-dvh flex-col overflow-hidden overscroll-none bg-background text-foreground">
                    {/* Brilho dourado no canto. Em estilo inline porque nasce
                        de um color-mix sobre o token --gold: uma classe
                        arbitraria com este gradiente dentro seria ilegivel. */}
                    <div
                        aria-hidden="true"
                        className="pointer-events-none absolute inset-0"
                        style={{
                            background:
                                'radial-gradient(90% 70% at 18% 10%, color-mix(in srgb, var(--gold) 9%, transparent) 0%, transparent 62%)',
                        }}
                    />

                    <header
                        className={cn(
                            'relative flex items-center justify-between gap-6 px-[clamp(1.5rem,6vw,5rem)] py-[clamp(1.5rem,4vw,2.75rem)] duration-700',
                            RISE_IN,
                        )}
                    >
                        <span className="flex items-center gap-3.5">
                            {/* Decorativo: o nome da marca esta no lockup ao
                                lado, em texto. */}
                            <AppLogoIcon
                                aria-hidden="true"
                                className="h-10 w-auto fill-current"
                            />
                            <span className="flex flex-col gap-1">
                                <span className="text-sm font-medium tracking-[0.34em] uppercase">
                                    Twelve
                                </span>
                                <span className="pl-0.5 text-[9px] font-medium tracking-[0.5em] text-muted-foreground uppercase">
                                    Studio
                                </span>
                            </span>
                        </span>

                        <span className="flex items-center gap-2.5 text-[11px] font-medium tracking-[0.16em] text-muted-foreground uppercase">
                            <span
                                aria-hidden="true"
                                className="size-[5px] shrink-0 rounded-full bg-gold motion-safe:animate-breathe"
                            />
                            Em preparação
                        </span>
                    </header>

                    <main className="relative mx-auto flex w-full max-w-[1240px] flex-1 flex-col justify-center px-[clamp(1.5rem,6vw,5rem)] py-[clamp(2rem,5vw,4rem)]">
                        <h1
                            className={cn(
                                'max-w-[15ch] text-[clamp(2.75rem,8.2vw,7.5rem)] leading-[0.94] font-semibold tracking-[-0.045em] text-balance delay-[60ms] duration-900',
                                RISE_IN,
                            )}
                        >
                            Peças impressas em 3D, feitas em Portugal.
                        </h1>

                        {/* Regua: dois blocos em vez de um linear-gradient com
                            paragens de percentagem, para as duas cores virem
                            de tokens em vez de hex. */}
                        <div
                            aria-hidden="true"
                            className="mt-[clamp(2.125rem,5vw,3.625rem)] mb-[clamp(1.625rem,3.4vw,2.25rem)] flex origin-left motion-safe:animate-wipe"
                        >
                            <span className="h-px shrink-0 basis-[12%] bg-gold" />
                            <span className="h-px flex-1 bg-border" />
                        </div>

                        <div
                            className={cn(
                                'flex flex-wrap items-start justify-between gap-[clamp(1.75rem,4vw,4rem)] delay-[180ms] duration-900',
                                RISE_IN,
                            )}
                        >
                            <div className="max-w-[44ch]">
                                <p className="text-[clamp(1rem,1.4vw,1.25rem)] leading-normal text-pretty text-muted-foreground">
                                    Decoração, gadgets e peças personalizadas —
                                    desenhadas e impressas peça a peça no nosso
                                    estúdio.
                                </p>

                                <Form
                                    {...notify.form()}
                                    resetOnSuccess
                                    className="mt-7 max-w-[430px]"
                                >
                                    {({
                                        processing,
                                        errors,
                                        wasSuccessful,
                                    }) => (
                                        <>
                                            <div className="flex flex-wrap items-center gap-3">
                                                <label
                                                    htmlFor="notify-email"
                                                    className="sr-only"
                                                >
                                                    Email
                                                </label>
                                                {/* O Input do shadcn e uma
                                                    caixa; o desenho pede um
                                                    sublinhado. Borda de 2px
                                                    para o foco se ver como
                                                    espessura e nao so como
                                                    troca de tom. */}
                                                <input
                                                    id="notify-email"
                                                    type="email"
                                                    name="email"
                                                    required
                                                    autoComplete="email"
                                                    placeholder="o teu email"
                                                    aria-invalid={
                                                        errors.email
                                                            ? true
                                                            : undefined
                                                    }
                                                    className="min-w-[190px] flex-1 border-0 border-b-2 border-input bg-transparent px-0.5 py-2.5 text-[15px] outline-none placeholder:text-muted-foreground focus-visible:border-gold aria-invalid:border-destructive"
                                                />
                                                <Button
                                                    type="submit"
                                                    disabled={processing}
                                                    className="h-auto rounded-full px-[22px] py-[11px] font-semibold"
                                                >
                                                    {processing && (
                                                        <LoaderCircle className="size-4 animate-spin" />
                                                    )}
                                                    Avisa-me
                                                </Button>
                                            </div>

                                            <InputError
                                                message={errors.email}
                                                className="mt-3"
                                            />

                                            {/* aria-live: quem usa leitor de
                                                ecra tem de ouvir a confirmacao
                                                sem ir procurar por ela. */}
                                            <p
                                                aria-live="polite"
                                                className="mt-3 text-[12.5px] text-muted-foreground"
                                            >
                                                {wasSuccessful
                                                    ? 'Obrigado — avisamos-te quando a loja abrir.'
                                                    : 'Avisamos-te no dia em que a loja abrir. Nada mais.'}
                                            </p>
                                        </>
                                    )}
                                </Form>
                            </div>

                            <p className="flex items-center gap-[11px] text-[15px] whitespace-nowrap text-primary-hover">
                                <span
                                    aria-hidden="true"
                                    className="size-1.5 flex-none rounded-full bg-gold"
                                />
                                A loja abre em breve.
                            </p>
                        </div>
                    </main>

                    <footer className="relative flex items-center justify-between gap-5 px-[clamp(1.5rem,6vw,5rem)] pb-[clamp(1.5rem,4vw,2.5rem)] text-[12.5px] text-muted-foreground">
                        <span>© {new Date().getFullYear()} 12studio</span>
                        <span className="tracking-[0.04em]">
                            Feito em Portugal
                        </span>
                    </footer>
                </div>
            </div>
        </>
    );
}
