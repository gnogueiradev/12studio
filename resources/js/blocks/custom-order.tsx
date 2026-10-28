import { Link, useForm, usePage } from '@inertiajs/react';
import { CheckCircle2, FileUp, MessageCircle, X } from 'lucide-react';
import { useRef, useState } from 'react';
import InputError from '@/components/input-error';
import { PrivacyNote } from '@/components/store/conversation-form';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useLocalizedUrl, useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { StoreBlock } from '@/types/store';
import { BlockSection } from './section';

type Data = { title: string; text: string };

type QuoteForm = {
    type: 'quote';
    message: string;
    details: {
        purpose: string;
        dimensions: string;
        quantity: number | null;
        color: string;
        material: string;
        deadline: string;
    };
    files: File[];
    name: string;
    email: string;
    phone: string;
    consent: boolean;
    started: string;
    website: string;
};

const ACCEPT = '.stl,.3mf,.obj,.step,.stp,.jpg,.jpeg,.png,.webp,.pdf';
const MAX_FILES = 3;
const MAX_BYTES = 20 * 1024 * 1024;

/** Em que passo vive cada campo (para saltar para o erro). */
const STEP_OF: Record<string, number> = {
    message: 0,
    'details.purpose': 0,
    'details.dimensions': 1,
    'details.quantity': 1,
    'details.color': 1,
    'details.material': 1,
    'details.deadline': 1,
    files: 1,
    name: 2,
    email: 2,
    phone: 2,
    consent: 2,
};

/**
 * O pedido de peça personalizada, em 3 passos: a ideia, os detalhes (com
 * ficheiros 3D, fotografias ou PDF) e o contacto. Abre uma conversa do tipo
 * `quote`; a resposta chega pelo link privado.
 */
export function CustomOrderBlock({ block }: { block: StoreBlock }) {
    const data = block.data as Data;
    const t = useT();
    const url = useLocalizedUrl();
    const { storefront } = usePage().props;
    const [step, setStep] = useState(0);
    const [sent, setSent] = useState<string | null>(null);
    const [localError, setLocalError] = useState<string | null>(null);
    const fileInput = useRef<HTMLInputElement>(null);
    const id = (field: string) => `quote-${block.id}-${field}`;

    const form = useForm<QuoteForm>({
        type: 'quote',
        message: '',
        details: {
            purpose: '',
            dimensions: '',
            quantity: 1,
            color: '',
            material: '',
            deadline: '',
        },
        files: [],
        name: '',
        email: '',
        phone: '',
        consent: false,
        started: storefront?.form.started ?? '',
        website: '',
    });
    const errors = form.errors as Record<string, string | undefined>;
    const steps = [
        t('conversation.step_idea'),
        t('conversation.step_details'),
        t('conversation.step_contact'),
    ];

    const setDetail = <K extends keyof QuoteForm['details']>(
        key: K,
        value: QuoteForm['details'][K],
    ) => form.setData('details', { ...form.data.details, [key]: value });

    const addFiles = (list: FileList | null) => {
        setLocalError(null);
        const files = [...form.data.files, ...Array.from(list ?? [])].slice(
            0,
            MAX_FILES,
        );

        if (files.reduce((sum, file) => sum + file.size, 0) > MAX_BYTES) {
            setLocalError(t('conversation.files_too_big'));

            return;
        }

        form.setData('files', files);
    };

    const next = () => {
        if (step === 0 && form.data.message.trim() === '') {
            form.setError('message', t('form.required'));

            return;
        }

        form.clearErrors();
        setStep((current) => Math.min(current + 1, steps.length - 1));
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        if (step < steps.length - 1) {
            next();

            return;
        }

        // O carimbo mais recente das props (ver ConversationForm).
        form.transform((current) => ({
            ...current,
            started: storefront?.form.started ?? current.started,
        }));
        form.post(url('/conversas'), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: (page) => {
                const flash = page.flash as {
                    conversation?: { url: string };
                };

                if (flash.conversation) {
                    setSent(flash.conversation.url);
                    form.reset();
                    setStep(0);
                }
            },
            onError: (failed) => {
                const first = Object.keys(failed)
                    .map((key) => STEP_OF[key.replace(/\.\d+$/, '')])
                    .filter((value) => value !== undefined)
                    .sort()[0];

                if (first !== undefined) {
                    setStep(first);
                }
            },
        });
    };

    return (
        <BlockSection style={block.style} id="pedido">
            <div className="mx-auto max-w-2xl rounded-xl border border-border/60 bg-card p-6 text-card-foreground md:p-10">
                {data.title && (
                    <h2 className="text-2xl font-semibold tracking-tight text-balance md:text-3xl">
                        {data.title}
                    </h2>
                )}
                {data.text && (
                    <p className="mt-3 text-base text-muted-foreground">
                        {data.text}
                    </p>
                )}

                {sent ? (
                    <div role="status" className="mt-8 flex flex-col gap-3">
                        <CheckCircle2
                            aria-hidden="true"
                            className="size-7 text-success"
                        />
                        <p className="text-lg font-semibold">
                            {t('conversation.sent_title')}
                        </p>
                        <p className="text-muted-foreground">
                            {t('conversation.quote_sent_text')}
                        </p>
                        <Button asChild className="self-start">
                            <Link href={sent}>
                                <MessageCircle aria-hidden="true" />
                                {t('conversation.view')}
                            </Link>
                        </Button>
                    </div>
                ) : (
                    <form onSubmit={submit} noValidate className="mt-8">
                        <div
                            aria-hidden="true"
                            className="absolute -left-[9999px]"
                        >
                            <label htmlFor={id('website')}>Website</label>
                            <input
                                id={id('website')}
                                tabIndex={-1}
                                autoComplete="off"
                                value={form.data.website}
                                onChange={(event) =>
                                    form.setData('website', event.target.value)
                                }
                            />
                        </div>

                        <p className="text-sm text-muted-foreground">
                            {t('conversation.step_of', {
                                current: step + 1,
                                total: steps.length,
                            })}
                            {' · '}
                            <span className="font-medium text-foreground">
                                {steps[step]}
                            </span>
                        </p>
                        <div
                            className="mt-2 flex gap-1.5"
                            role="progressbar"
                            aria-valuemin={1}
                            aria-valuemax={steps.length}
                            aria-valuenow={step + 1}
                            aria-label={steps[step]}
                        >
                            {steps.map((label, index) => (
                                <span
                                    key={label}
                                    className={cn(
                                        'h-1.5 flex-1 rounded-full',
                                        index <= step ? 'bg-gold' : 'bg-muted',
                                    )}
                                />
                            ))}
                        </div>

                        <InputError message={errors.started} className="mt-4" />

                        <div className="mt-6 grid gap-4">
                            {step === 0 && (
                                <>
                                    <Field
                                        id={id('message')}
                                        label={t('conversation.idea')}
                                        error={errors.message}
                                    >
                                        <Textarea
                                            id={id('message')}
                                            rows={6}
                                            maxLength={5000}
                                            placeholder={t(
                                                'conversation.idea_placeholder',
                                            )}
                                            value={form.data.message}
                                            onChange={(event) =>
                                                form.setData(
                                                    'message',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                    <Field
                                        id={id('purpose')}
                                        label={t('conversation.purpose')}
                                        optional
                                        error={errors['details.purpose']}
                                    >
                                        <Input
                                            id={id('purpose')}
                                            maxLength={300}
                                            value={form.data.details.purpose}
                                            onChange={(event) =>
                                                setDetail(
                                                    'purpose',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                </>
                            )}

                            {step === 1 && (
                                <>
                                    <div className="grid gap-4 sm:grid-cols-[1fr_8rem]">
                                        <Field
                                            id={id('dimensions')}
                                            label={t('conversation.dimensions')}
                                            optional
                                            error={errors['details.dimensions']}
                                        >
                                            <Input
                                                id={id('dimensions')}
                                                maxLength={200}
                                                placeholder={t(
                                                    'conversation.dimensions_placeholder',
                                                )}
                                                value={
                                                    form.data.details.dimensions
                                                }
                                                onChange={(event) =>
                                                    setDetail(
                                                        'dimensions',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </Field>
                                        <Field
                                            id={id('quantity')}
                                            label={t('form.quantity')}
                                            error={errors['details.quantity']}
                                        >
                                            <Input
                                                id={id('quantity')}
                                                type="number"
                                                inputMode="numeric"
                                                min={1}
                                                max={9999}
                                                value={
                                                    form.data.details
                                                        .quantity ?? ''
                                                }
                                                onChange={(event) =>
                                                    setDetail(
                                                        'quantity',
                                                        event.target.value ===
                                                            ''
                                                            ? null
                                                            : Number(
                                                                  event.target
                                                                      .value,
                                                              ),
                                                    )
                                                }
                                            />
                                        </Field>
                                    </div>
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <Field
                                            id={id('color')}
                                            label={t('conversation.color')}
                                            optional
                                            error={errors['details.color']}
                                        >
                                            <Input
                                                id={id('color')}
                                                maxLength={100}
                                                placeholder={t(
                                                    'conversation.dont_know',
                                                )}
                                                value={form.data.details.color}
                                                onChange={(event) =>
                                                    setDetail(
                                                        'color',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </Field>
                                        <Field
                                            id={id('material')}
                                            label={t('conversation.material')}
                                            optional
                                            error={errors['details.material']}
                                        >
                                            <Input
                                                id={id('material')}
                                                maxLength={100}
                                                placeholder={t(
                                                    'conversation.dont_know',
                                                )}
                                                value={
                                                    form.data.details.material
                                                }
                                                onChange={(event) =>
                                                    setDetail(
                                                        'material',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </Field>
                                    </div>
                                    <Field
                                        id={id('deadline')}
                                        label={t('conversation.deadline')}
                                        optional
                                        error={errors['details.deadline']}
                                    >
                                        <Input
                                            id={id('deadline')}
                                            type="date"
                                            className="w-48"
                                            value={form.data.details.deadline}
                                            onChange={(event) =>
                                                setDetail(
                                                    'deadline',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                    <div className="grid gap-2">
                                        <Label htmlFor={id('files')}>
                                            {t('form.files')}{' '}
                                            <span className="font-normal text-muted-foreground">
                                                ({t('form.optional')})
                                            </span>
                                        </Label>
                                        <div
                                            className="flex flex-col items-center gap-2 rounded-lg border border-dashed border-border p-6 text-center text-sm"
                                            onDragOver={(event) =>
                                                event.preventDefault()
                                            }
                                            onDrop={(event) => {
                                                event.preventDefault();
                                                addFiles(
                                                    event.dataTransfer.files,
                                                );
                                            }}
                                        >
                                            <FileUp
                                                aria-hidden="true"
                                                className="size-6 text-muted-foreground"
                                            />
                                            <input
                                                ref={fileInput}
                                                id={id('files')}
                                                type="file"
                                                multiple
                                                accept={ACCEPT}
                                                className="sr-only"
                                                onChange={(event) => {
                                                    addFiles(
                                                        event.target.files,
                                                    );
                                                    event.target.value = '';
                                                }}
                                            />
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                disabled={
                                                    form.data.files.length >=
                                                    MAX_FILES
                                                }
                                                onClick={() =>
                                                    fileInput.current?.click()
                                                }
                                            >
                                                {t('form.choose_files')}
                                            </Button>
                                            <span className="text-muted-foreground">
                                                {t('form.drop_files')}
                                            </span>
                                        </div>
                                        <p className="text-xs text-muted-foreground">
                                            {t('form.files_help')}
                                        </p>
                                        {form.data.files.length > 0 && (
                                            <ul className="grid gap-1">
                                                {form.data.files.map(
                                                    (file, index) => (
                                                        <li
                                                            key={`${file.name}-${index}`}
                                                            className="flex items-center gap-2 rounded-md bg-muted px-3 py-1.5 text-sm"
                                                        >
                                                            <span className="min-w-0 flex-1 truncate">
                                                                {file.name}
                                                            </span>
                                                            <span className="text-xs text-muted-foreground">
                                                                {(
                                                                    file.size /
                                                                    1024 /
                                                                    1024
                                                                ).toFixed(
                                                                    1,
                                                                )}{' '}
                                                                MB
                                                            </span>
                                                            <button
                                                                type="button"
                                                                className="rounded p-0.5 hover:bg-background"
                                                                aria-label={t(
                                                                    'form.remove_file',
                                                                    {
                                                                        name: file.name,
                                                                    },
                                                                )}
                                                                onClick={() =>
                                                                    form.setData(
                                                                        'files',
                                                                        form.data.files.filter(
                                                                            (
                                                                                _,
                                                                                i,
                                                                            ) =>
                                                                                i !==
                                                                                index,
                                                                        ),
                                                                    )
                                                                }
                                                            >
                                                                <X className="size-4" />
                                                            </button>
                                                        </li>
                                                    ),
                                                )}
                                            </ul>
                                        )}
                                        <InputError
                                            message={
                                                localError ??
                                                errors.files ??
                                                Object.entries(errors).find(
                                                    ([key]) =>
                                                        key.startsWith(
                                                            'files.',
                                                        ),
                                                )?.[1]
                                            }
                                        />
                                    </div>
                                </>
                            )}

                            {step === 2 && (
                                <>
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <Field
                                            id={id('name')}
                                            label={t('form.name')}
                                            error={errors.name}
                                        >
                                            <Input
                                                id={id('name')}
                                                autoComplete="name"
                                                maxLength={120}
                                                value={form.data.name}
                                                onChange={(event) =>
                                                    form.setData(
                                                        'name',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </Field>
                                        <Field
                                            id={id('email')}
                                            label={t('form.email')}
                                            error={errors.email}
                                        >
                                            <Input
                                                id={id('email')}
                                                type="email"
                                                autoComplete="email"
                                                maxLength={255}
                                                value={form.data.email}
                                                onChange={(event) =>
                                                    form.setData(
                                                        'email',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </Field>
                                    </div>
                                    <Field
                                        id={id('phone')}
                                        label={t('form.phone')}
                                        optional
                                        error={errors.phone}
                                    >
                                        <Input
                                            id={id('phone')}
                                            type="tel"
                                            autoComplete="tel"
                                            maxLength={30}
                                            className="sm:w-1/2"
                                            value={form.data.phone}
                                            onChange={(event) =>
                                                form.setData(
                                                    'phone',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                    <div className="grid gap-1.5">
                                        <Label className="flex items-start gap-3 font-normal">
                                            <Checkbox
                                                checked={form.data.consent}
                                                onCheckedChange={(checked) =>
                                                    form.setData(
                                                        'consent',
                                                        checked === true,
                                                    )
                                                }
                                                className="mt-0.5"
                                            />
                                            <span className="text-sm">
                                                {t('form.consent')}
                                            </span>
                                        </Label>
                                        <InputError message={errors.consent} />
                                    </div>
                                    <PrivacyNote />
                                </>
                            )}
                        </div>

                        <div className="mt-8 flex items-center gap-3">
                            {step > 0 && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={() => setStep(step - 1)}
                                >
                                    {t('conversation.back')}
                                </Button>
                            )}
                            <span className="flex-1" />
                            <Button
                                type="submit"
                                size="lg"
                                disabled={form.processing}
                            >
                                {form.processing && <Spinner />}
                                {step < steps.length - 1
                                    ? t('conversation.next')
                                    : form.processing
                                      ? t('form.sending')
                                      : t('form.send')}
                            </Button>
                        </div>
                    </form>
                )}
            </div>
        </BlockSection>
    );
}

function Field({
    id,
    label,
    optional = false,
    error,
    children,
}: {
    id: string;
    label: string;
    optional?: boolean;
    error?: string;
    children: React.ReactNode;
}) {
    const t = useT();

    return (
        <div className="grid gap-1.5">
            <Label htmlFor={id}>
                {label}
                {optional && (
                    <span className="font-normal text-muted-foreground">
                        {' '}
                        ({t('form.optional')})
                    </span>
                )}
            </Label>
            {children}
            <InputError message={error} />
        </div>
    );
}
