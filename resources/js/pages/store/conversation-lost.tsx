import { Head, useForm } from '@inertiajs/react';
import { Mail } from 'lucide-react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useLocalizedUrl, useT } from '@/lib/i18n';

/**
 * "Perdeste o link?": o cliente escreve o email e recebe de novo o link das
 * conversas dele. A resposta é sempre a mesma, haja conversas ou não.
 */
export default function ConversationLost() {
    const t = useT();
    const url = useLocalizedUrl();
    const form = useForm({ email: '' });

    return (
        <>
            <Head title={t('conversation.resend_title')}>
                <meta name="robots" content="noindex" />
            </Head>
            <div className="mx-auto flex w-full max-w-md flex-col gap-4 px-4 py-16">
                <h1 className="text-2xl font-semibold tracking-tight">
                    {t('conversation.resend_title')}
                </h1>
                <p className="text-muted-foreground">
                    {t('conversation.resend_text')}
                </p>
                <form
                    className="flex flex-col gap-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(url('/conversa/reenviar'), {
                            preserveScroll: true,
                            // A confirmação chega pelo toast (é a mesma,
                            // haja conversas com esse email ou não).
                            onSuccess: () => form.reset(),
                        });
                    }}
                >
                    <Label htmlFor="lost-email">{t('form.email')}</Label>
                    <Input
                        id="lost-email"
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
                        {t('conversation.resend_button')}
                    </Button>
                </form>
            </div>
        </>
    );
}
