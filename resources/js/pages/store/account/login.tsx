import { Head, useForm } from '@inertiajs/react';
import { Mail } from 'lucide-react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useLocalizedUrl, useT } from '@/lib/i18n';

/**
 * "Entrar na tua conta": o cliente escreve o email e recebe um link para
 * entrar, sem password. A resposta é sempre a mesma (pelo toast), haja conta
 * com esse email ou não.
 */
export default function AccountLogin() {
    const t = useT();
    const url = useLocalizedUrl();
    const form = useForm({ email: '' });

    return (
        <>
            <Head title={t('account.login_title')}>
                <meta name="robots" content="noindex" />
            </Head>
            <div className="mx-auto flex w-full max-w-md flex-col gap-4 px-4 py-16">
                <h1 className="text-2xl font-semibold tracking-tight">
                    {t('account.login_title')}
                </h1>
                <p className="text-muted-foreground">
                    {t('account.login_text')}
                </p>
                <form
                    className="flex flex-col gap-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(url('/entrar/enviar'), {
                            preserveScroll: true,
                            onSuccess: () => form.reset(),
                        });
                    }}
                >
                    <Label htmlFor="login-email">{t('form.email')}</Label>
                    <Input
                        id="login-email"
                        type="email"
                        autoComplete="email"
                        required
                        value={form.data.email}
                        onChange={(event) =>
                            form.setData('email', event.target.value)
                        }
                    />
                    <InputError message={form.errors.email} />
                    <Button
                        type="submit"
                        disabled={form.processing}
                        className="self-start"
                    >
                        {form.processing ? (
                            <Spinner />
                        ) : (
                            <Mail aria-hidden="true" />
                        )}
                        {t('account.send')}
                    </Button>
                </form>
            </div>
        </>
    );
}
