import { Head, useForm } from '@inertiajs/react';
import { MailX } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useT } from '@/lib/i18n';

/**
 * O /ofertas/sair/{user}, aberto a partir do rodapé dos emails de ofertas.
 * Abrir não recusa: os leitores de email abrem os links sozinhos. Quem recusa
 * é o botão, que faz o POST para o mesmo endereço assinado (o `action`, que
 * por isso não passa pelo useLocalizedUrl).
 */
export default function OffersUnsubscribe({
    action,
    done,
}: {
    action: string;
    done: boolean;
}) {
    const t = useT();
    const form = useForm({});

    return (
        <>
            <Head title={t('offers.unsubscribe_title')}>
                <meta name="robots" content="noindex" />
            </Head>
            <div className="mx-auto flex w-full max-w-md flex-col gap-4 px-4 py-16">
                <h1 className="text-2xl font-semibold tracking-tight">
                    {t('offers.unsubscribe_title')}
                </h1>
                {done ? (
                    <p role="status" className="text-muted-foreground">
                        {t('offers.unsubscribed')}
                    </p>
                ) : (
                    <form
                        className="flex flex-col gap-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post(action, { preserveScroll: true });
                        }}
                    >
                        <p className="text-muted-foreground">
                            {t('offers.unsubscribe_text')}
                        </p>
                        <Button
                            type="submit"
                            disabled={form.processing}
                            className="self-start"
                        >
                            {form.processing ? (
                                <Spinner />
                            ) : (
                                <MailX aria-hidden="true" />
                            )}
                            {t('offers.unsubscribe_button')}
                        </Button>
                    </form>
                )}
            </div>
        </>
    );
}
