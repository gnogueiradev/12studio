import { Head, Link, useForm } from '@inertiajs/react';
import { LogIn } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useLocalizedUrl, useT } from '@/lib/i18n';

/**
 * O /entrar/{token}, aberto a partir do email. Abrir não entra: os leitores de
 * email abrem os links sozinhos e gastavam-no. Quem entra é o botão, com o
 * email mascarado para o cliente ver que é o dele. Link gasto ou expirado:
 * pede-se outro.
 */
export default function AccountConfirm({
    token,
    email,
}: {
    token: string;
    email: string | null;
}) {
    const t = useT();
    const url = useLocalizedUrl();
    const form = useForm({});

    return (
        <>
            <Head title={t('account.login_title')}>
                <meta name="robots" content="noindex" />
            </Head>
            <div className="mx-auto flex w-full max-w-md flex-col gap-4 px-4 py-16">
                <h1 className="text-2xl font-semibold tracking-tight">
                    {t('account.login_title')}
                </h1>
                {email === null ? (
                    <>
                        <p className="text-muted-foreground">
                            {t('account.link_invalid')}
                        </p>
                        <Button asChild className="self-start">
                            <Link href={url('/entrar')}>
                                {t('account.ask_again')}
                            </Link>
                        </Button>
                    </>
                ) : (
                    <form
                        className="flex flex-col gap-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post(url(`/entrar/${token}/confirmar`));
                        }}
                    >
                        <p className="text-muted-foreground">
                            {t('account.confirm_text', { email })}
                        </p>
                        <Button
                            type="submit"
                            disabled={form.processing}
                            className="self-start"
                        >
                            {form.processing ? (
                                <Spinner />
                            ) : (
                                <LogIn aria-hidden="true" />
                            )}
                            {t('account.enter')}
                        </Button>
                    </form>
                )}
            </div>
        </>
    );
}
