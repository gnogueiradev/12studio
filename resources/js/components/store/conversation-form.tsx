import { Link, useForm, usePage } from '@inertiajs/react';
import { CheckCircle2, MessageCircle } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useLocalizedUrl, useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export type ConversationFormProps = {
    /** "question" é uma pergunta sobre um produto: abre como contacto. */
    kind: 'contact' | 'interest' | 'question';
    product?: { id: number; name: string } | null;
    variantId?: number | null;
    /** Os campos de personalização do produto (só no "Tenho interesse"). */
    personalization?: { key: string; label: string }[];
    /** Um prefixo para os ids (o mesmo formulário pode estar duas vezes). */
    idPrefix: string;
    className?: string;
};

type FormData = {
    type: 'contact' | 'interest';
    name: string;
    email: string;
    phone: string;
    message: string;
    product_id: number | null;
    variant_id: number | null;
    quantity: number | null;
    personalization: Record<string, string>;
    started: string;
    website: string;
};

/**
 * O formulário que abre uma conversa com a loja. Sem conta: o cliente recebe
 * por email o link privado da conversa, e é por lá que lhe respondemos.
 *
 * O campo `website` está escondido (só um robô o preenche) e o `started` vem
 * do servidor (a hora a que a página abriu) — ver OpenConversationRequest.
 */
export function ConversationForm({
    kind,
    product = null,
    variantId = null,
    personalization = [],
    idPrefix,
    className,
}: ConversationFormProps) {
    const t = useT();
    const url = useLocalizedUrl();
    const { storefront } = usePage().props;
    const [sent, setSent] = useState<string | null>(null);

    const { data, setData, post, processing, errors, reset, transform } =
        useForm<FormData>({
            type: kind === 'interest' ? 'interest' : 'contact',
            name: '',
            email: '',
            phone: '',
            message: '',
            product_id: product?.id ?? null,
            variant_id: kind === 'interest' ? variantId : null,
            quantity: kind === 'interest' ? 1 : null,
            personalization: {},
            started: storefront?.form.started ?? '',
            website: '',
        });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        // A variante pode ter mudado desde que o formulário abriu.
        transform((current) => ({
            ...current,
            variant_id: kind === 'interest' ? variantId : null,
        }));
        post(url('/conversas'), {
            preserveScroll: true,
            onSuccess: (page) => {
                const flash = page.flash as {
                    conversation?: { url: string };
                };

                if (flash.conversation) {
                    setSent(flash.conversation.url);
                    reset('message', 'personalization');
                }
            },
        });
    };

    const id = (field: string) => `${idPrefix}-${field}`;
    const fieldErrors = errors as Record<string, string | undefined>;

    if (sent) {
        return (
            <div
                role="status"
                className={cn(
                    'flex flex-col items-start gap-3 rounded-xl border border-border/60 bg-card p-6 text-card-foreground',
                    className,
                )}
            >
                <CheckCircle2
                    aria-hidden="true"
                    className="size-6 text-success"
                />
                <p className="text-lg font-semibold">
                    {t('conversation.sent_title')}
                </p>
                <p className="text-sm text-muted-foreground">
                    {t('conversation.sent_text')}
                </p>
                <div className="flex flex-wrap gap-2 pt-2">
                    <Button asChild>
                        <Link href={sent}>
                            <MessageCircle aria-hidden="true" />
                            {t('conversation.view')}
                        </Link>
                    </Button>
                    <Button variant="ghost" onClick={() => setSent(null)}>
                        {t('conversation.write_again')}
                    </Button>
                </div>
            </div>
        );
    }

    return (
        <form
            onSubmit={submit}
            className={cn('flex flex-col gap-4', className)}
            noValidate
        >
            {/* Só um robô preenche isto. */}
            <div aria-hidden="true" className="absolute -left-[9999px]">
                <label htmlFor={id('website')}>Website</label>
                <input
                    id={id('website')}
                    name="website"
                    tabIndex={-1}
                    autoComplete="off"
                    value={data.website}
                    onChange={(event) => setData('website', event.target.value)}
                />
            </div>

            <InputError message={fieldErrors.started} />

            {kind === 'interest' && (
                <div className="grid gap-1.5">
                    <Label htmlFor={id('quantity')}>{t('form.quantity')}</Label>
                    <Input
                        id={id('quantity')}
                        type="number"
                        inputMode="numeric"
                        min={1}
                        max={999}
                        className="w-28"
                        value={data.quantity ?? ''}
                        onChange={(event) =>
                            setData(
                                'quantity',
                                event.target.value === ''
                                    ? null
                                    : Number(event.target.value),
                            )
                        }
                    />
                    <InputError message={errors.quantity} />
                </div>
            )}

            {kind === 'interest' &&
                personalization.map((field) => (
                    <div key={field.key} className="grid gap-1.5">
                        <Label htmlFor={id(`p-${field.key}`)}>
                            {field.label}
                        </Label>
                        <Input
                            id={id(`p-${field.key}`)}
                            maxLength={100}
                            value={data.personalization[field.key] ?? ''}
                            onChange={(event) =>
                                setData('personalization', {
                                    ...data.personalization,
                                    [field.key]: event.target.value,
                                })
                            }
                        />
                        <InputError
                            message={
                                fieldErrors[`personalization.${field.key}`]
                            }
                        />
                    </div>
                ))}

            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-1.5">
                    <Label htmlFor={id('name')}>{t('form.name')}</Label>
                    <Input
                        id={id('name')}
                        autoComplete="name"
                        maxLength={120}
                        required
                        aria-invalid={errors.name ? true : undefined}
                        value={data.name}
                        onChange={(event) =>
                            setData('name', event.target.value)
                        }
                    />
                    <InputError message={errors.name} />
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor={id('email')}>{t('form.email')}</Label>
                    <Input
                        id={id('email')}
                        type="email"
                        autoComplete="email"
                        maxLength={255}
                        required
                        aria-invalid={errors.email ? true : undefined}
                        value={data.email}
                        onChange={(event) =>
                            setData('email', event.target.value)
                        }
                    />
                    <InputError message={errors.email} />
                </div>
            </div>

            {kind === 'interest' && (
                <div className="grid gap-1.5">
                    <Label htmlFor={id('phone')}>
                        {t('form.phone')}{' '}
                        <span className="font-normal text-muted-foreground">
                            ({t('form.optional')})
                        </span>
                    </Label>
                    <Input
                        id={id('phone')}
                        type="tel"
                        autoComplete="tel"
                        maxLength={30}
                        value={data.phone}
                        onChange={(event) =>
                            setData('phone', event.target.value)
                        }
                    />
                    <InputError message={errors.phone} />
                </div>
            )}

            <div className="grid gap-1.5">
                <Label htmlFor={id('message')}>{t('form.message')}</Label>
                <Textarea
                    id={id('message')}
                    rows={4}
                    maxLength={5000}
                    required
                    aria-invalid={errors.message ? true : undefined}
                    placeholder={t(
                        kind === 'interest'
                            ? 'form.message_interest'
                            : kind === 'question'
                              ? 'form.message_question'
                              : 'form.message_contact',
                    )}
                    value={data.message}
                    onChange={(event) => setData('message', event.target.value)}
                />
                <InputError message={errors.message} />
            </div>

            <InputError message={fieldErrors.variant_id} />
            <InputError message={fieldErrors.product_id} />

            <Button
                type="submit"
                size="lg"
                disabled={processing}
                className="self-start"
            >
                {processing && <Spinner />}
                {processing ? t('form.sending') : t('form.send')}
            </Button>

            <PrivacyNote />
        </form>
    );
}

/** "Ao enviar, aceitas… política de privacidade." */
export function PrivacyNote() {
    const t = useT();
    const url = useLocalizedUrl();

    return (
        <p className="text-xs text-muted-foreground">
            {t('form.privacy_note')}{' '}
            <Link href={url('/privacidade')} className="underline">
                {t('form.privacy_link')}
            </Link>
            .
        </p>
    );
}
