import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { centsToInput } from '@/lib/money';
import { guardar as saveDestination } from '@/routes/admin/etsy/envios/destinos';
import { guardar as savePolicy } from '@/routes/admin/etsy/envios/devolucoes';
import {
    aplicar as assignProfile,
    guardar as saveProfile,
} from '@/routes/admin/etsy/envios/perfis';
import { guardar as saveProcessing } from '@/routes/admin/etsy/envios/processamento';
import { guardar as saveUpgrade } from '@/routes/admin/etsy/envios/upgrades';
import type {
    ProcessingProfile,
    ReturnPolicy,
    ShippingDestination,
    ShippingProductOption,
    ShippingProfile,
    ShippingUpgrade,
} from '@/types/etsy';
import {
    DESTINATION_REGIONS,
    etsyRefused,
    PROCESSING_STATES,
} from '@/types/etsy';

/*
 * Os diálogos de Etsy → Envios. Todos gravam diretamente na Etsy, e todos
 * seguem o mesmo molde dos outros modais do backoffice: quem os monta dá-lhes
 * uma `key` que muda com o alvo, e o remonte trata de os pôr com os valores
 * certos — não há efeitos a sincronizar formulários.
 */

type DialogProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

function Shell({
    open,
    onOpenChange,
    title,
    description,
    children,
    onSubmit,
    processing,
    submitLabel,
    canSubmit = true,
}: DialogProps & {
    title: string;
    description: ReactNode;
    children: ReactNode;
    onSubmit: () => void;
    processing: boolean;
    submitLabel: string;
    canSubmit?: boolean;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription asChild>
                        <div>{description}</div>
                    </DialogDescription>
                </DialogHeader>

                <div className="flex flex-col gap-4">{children}</div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                        disabled={processing}
                    >
                        Cancelar
                    </Button>
                    <Button
                        type="button"
                        onClick={onSubmit}
                        disabled={processing || !canSubmit}
                    >
                        {processing && <Spinner />}
                        {submitLabel}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function Field({
    id,
    label,
    error,
    hint,
    children,
}: {
    id: string;
    label: string;
    error?: string;
    hint?: string;
    children: ReactNode;
}) {
    return (
        <div className="grid gap-1.5">
            <Label htmlFor={id}>{label}</Label>
            {children}
            {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
            <InputError message={error} />
        </div>
    );
}

/** País ou região: o que a Etsy aceita como destino. */
type Target = 'country' | 'eu' | 'non_eu' | 'none';

function TargetFields({
    idPrefix,
    target,
    country,
    onTarget,
    onCountry,
    errors,
}: {
    idPrefix: string;
    target: Target;
    country: string;
    onTarget: (target: Target) => void;
    onCountry: (country: string) => void;
    errors: { destination_country_iso?: string; destination_region?: string };
}) {
    return (
        <div className="grid gap-3 sm:grid-cols-2">
            <Field
                id={`${idPrefix}-target`}
                label="Para onde"
                error={errors.destination_region}
            >
                <Select
                    value={target}
                    onValueChange={(value) => onTarget(value as Target)}
                >
                    <SelectTrigger id={`${idPrefix}-target`} className="w-full">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="country">Um país</SelectItem>
                        {DESTINATION_REGIONS.map((region) => (
                            <SelectItem key={region.value} value={region.value}>
                                {region.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </Field>

            {target === 'country' && (
                <Field
                    id={`${idPrefix}-country`}
                    label="País"
                    hint="Código de duas letras: PT, ES, FR…"
                    error={errors.destination_country_iso}
                >
                    <Input
                        id={`${idPrefix}-country`}
                        value={country}
                        maxLength={2}
                        className="uppercase"
                        onChange={(event) =>
                            onCountry(event.target.value.toUpperCase())
                        }
                    />
                </Field>
            )}
        </div>
    );
}

function CostAndDeliveryFields({
    idPrefix,
    labels,
    values,
    onChange,
    errors,
}: {
    idPrefix: string;
    labels: { cost: string; extra: string };
    values: { cost: string; extra: string; minDays: string; maxDays: string };
    onChange: (
        field: 'cost' | 'extra' | 'minDays' | 'maxDays',
        value: string,
    ) => void;
    errors: {
        cost?: string;
        extra?: string;
        minDays?: string;
        maxDays?: string;
    };
}) {
    return (
        <>
            <div className="grid gap-3 sm:grid-cols-2">
                <Field
                    id={`${idPrefix}-cost`}
                    label={labels.cost}
                    error={errors.cost}
                >
                    <Input
                        id={`${idPrefix}-cost`}
                        inputMode="decimal"
                        placeholder="4,50"
                        value={values.cost}
                        onChange={(event) =>
                            onChange('cost', event.target.value)
                        }
                    />
                </Field>
                <Field
                    id={`${idPrefix}-extra`}
                    label={labels.extra}
                    hint="0 se não houver."
                    error={errors.extra}
                >
                    <Input
                        id={`${idPrefix}-extra`}
                        inputMode="decimal"
                        placeholder="0"
                        value={values.extra}
                        onChange={(event) =>
                            onChange('extra', event.target.value)
                        }
                    />
                </Field>
            </div>
            <div className="grid gap-3 sm:grid-cols-2">
                <Field
                    id={`${idPrefix}-min-days`}
                    label="Entrega, dias mínimos"
                    error={errors.minDays}
                >
                    <Input
                        id={`${idPrefix}-min-days`}
                        type="number"
                        min={1}
                        max={45}
                        value={values.minDays}
                        onChange={(event) =>
                            onChange('minDays', event.target.value)
                        }
                    />
                </Field>
                <Field
                    id={`${idPrefix}-max-days`}
                    label="Entrega, dias máximos"
                    error={errors.maxDays}
                >
                    <Input
                        id={`${idPrefix}-max-days`}
                        type="number"
                        min={1}
                        max={45}
                        value={values.maxDays}
                        onChange={(event) =>
                            onChange('maxDays', event.target.value)
                        }
                    />
                </Field>
            </div>
        </>
    );
}

function daysToInput(days: number | null | undefined): string {
    return days === null || days === undefined ? '' : String(days);
}

/**
 * Criar um perfil (com o primeiro destino) ou mudar-lhe o título e a origem.
 *
 * Ao editar não há custos no formulário de propósito: na Etsy vivem nos
 * destinos, e um campo aqui dava a ideia de que se mudavam por este lado.
 */
export function ProfileDialog({
    open,
    onOpenChange,
    editing,
}: DialogProps & { editing: ShippingProfile | null }) {
    const { data, setData, post, processing, errors, transform } = useForm({
        shipping_profile_id: editing?.id ?? null,
        title: editing?.title ?? '',
        origin_country_iso: editing?.originCountry ?? 'PT',
        origin_postal_code: editing?.originPostalCode ?? '',
        target: 'country' as Target,
        destination_country_iso: 'PT',
        primary_cost: '',
        secondary_cost: '0',
        min_delivery_days: '',
        max_delivery_days: '',
    });

    transform(({ target, ...rest }) => ({
        ...rest,
        destination_country_iso:
            target === 'country' ? rest.destination_country_iso : null,
        destination_region: target === 'country' ? null : target,
    }));

    const submit = () =>
        post(saveProfile().url, {
            preserveScroll: true,
            onSuccess: (page) => !etsyRefused(page) && onOpenChange(false),
        });

    return (
        <Shell
            open={open}
            onOpenChange={onOpenChange}
            title={editing ? 'Editar perfil de envio' : 'Novo perfil de envio'}
            description={
                editing
                    ? 'Só o nome e a origem. Os custos mudam-se em cada destino, mais abaixo no perfil.'
                    : 'Um perfil nasce com o primeiro destino. Os outros acrescentam-se depois.'
            }
            onSubmit={submit}
            processing={processing}
            submitLabel={editing ? 'Guardar na Etsy' : 'Criar na Etsy'}
            canSubmit={data.title.trim() !== ''}
        >
            <Field
                id="profile-title"
                label="Nome do perfil"
                hint="Só o vês tu, na gestão da loja."
                error={errors.title}
            >
                <Input
                    id="profile-title"
                    value={data.title}
                    maxLength={255}
                    autoFocus
                    onChange={(event) => setData('title', event.target.value)}
                />
            </Field>

            <div className="grid gap-3 sm:grid-cols-2">
                <Field
                    id="profile-origin"
                    label="País de origem"
                    error={errors.origin_country_iso}
                >
                    <Input
                        id="profile-origin"
                        value={data.origin_country_iso}
                        maxLength={2}
                        className="uppercase"
                        onChange={(event) =>
                            setData(
                                'origin_country_iso',
                                event.target.value.toUpperCase(),
                            )
                        }
                    />
                </Field>
                <Field
                    id="profile-postal"
                    label="Código postal de origem"
                    error={errors.origin_postal_code}
                >
                    <Input
                        id="profile-postal"
                        value={data.origin_postal_code}
                        onChange={(event) =>
                            setData('origin_postal_code', event.target.value)
                        }
                    />
                </Field>
            </div>

            {!editing && (
                <>
                    <p className="border-t border-border/60 pt-3 text-xs font-medium tracking-wider text-muted-foreground uppercase">
                        Primeiro destino
                    </p>
                    <TargetFields
                        idPrefix="profile"
                        target={data.target}
                        country={data.destination_country_iso}
                        onTarget={(target) => setData('target', target)}
                        onCountry={(country) =>
                            setData('destination_country_iso', country)
                        }
                        errors={
                            errors as {
                                destination_country_iso?: string;
                                destination_region?: string;
                            }
                        }
                    />
                    <CostAndDeliveryFields
                        idPrefix="profile"
                        labels={{
                            cost: 'Custo de envio',
                            extra: 'Por artigo adicional',
                        }}
                        values={{
                            cost: data.primary_cost,
                            extra: data.secondary_cost,
                            minDays: data.min_delivery_days,
                            maxDays: data.max_delivery_days,
                        }}
                        onChange={(field, value) =>
                            setData(
                                {
                                    cost: 'primary_cost',
                                    extra: 'secondary_cost',
                                    minDays: 'min_delivery_days',
                                    maxDays: 'max_delivery_days',
                                }[field] as 'primary_cost',
                                value,
                            )
                        }
                        errors={{
                            cost: errors.primary_cost,
                            extra: errors.secondary_cost,
                            minDays: errors.min_delivery_days,
                            maxDays: errors.max_delivery_days,
                        }}
                    />
                </>
            )}
        </Shell>
    );
}

/** Um destino: para onde, por quanto e em quanto tempo. */
export function DestinationDialog({
    open,
    onOpenChange,
    profile,
    editing,
}: DialogProps & {
    profile: ShippingProfile;
    editing: ShippingDestination | null;
}) {
    const { data, setData, post, processing, errors, transform } = useForm({
        destination_id: editing?.id ?? null,
        target: (editing === null || editing.country
            ? 'country'
            : editing.region) as Target,
        destination_country_iso: editing?.country ?? '',
        primary_cost: centsToInput(editing?.primaryCostCents ?? null),
        secondary_cost: editing
            ? centsToInput(editing.secondaryCostCents ?? 0)
            : '0',
        min_delivery_days: daysToInput(editing?.minDays),
        max_delivery_days: daysToInput(editing?.maxDays),
    });

    transform(({ target, ...rest }) => ({
        ...rest,
        destination_country_iso:
            target === 'country' ? rest.destination_country_iso : null,
        destination_region: target === 'country' ? null : target,
    }));

    const submit = () =>
        post(saveDestination(profile.id).url, {
            preserveScroll: true,
            onSuccess: (page) => !etsyRefused(page) && onOpenChange(false),
        });

    return (
        <Shell
            open={open}
            onOpenChange={onOpenChange}
            title={editing ? 'Editar destino' : 'Novo destino'}
            description={
                <>
                    <p>Perfil “{profile.title}”.</p>
                    {profile.activeListings > 0 && (
                        <p className="mt-1 text-warning">
                            Este perfil é usado por {profile.activeListings}{' '}
                            {profile.activeListings === 1
                                ? 'anúncio à venda'
                                : 'anúncios à venda'}
                            : o que gravares aqui muda já os portes que os
                            compradores veem.
                        </p>
                    )}
                </>
            }
            onSubmit={submit}
            processing={processing}
            submitLabel="Guardar na Etsy"
        >
            <TargetFields
                idPrefix="destination"
                target={data.target}
                country={data.destination_country_iso}
                onTarget={(target) => setData('target', target)}
                onCountry={(country) =>
                    setData('destination_country_iso', country)
                }
                errors={
                    errors as {
                        destination_country_iso?: string;
                        destination_region?: string;
                    }
                }
            />
            <CostAndDeliveryFields
                idPrefix="destination"
                labels={{
                    cost: 'Custo de envio',
                    extra: 'Por artigo adicional',
                }}
                values={{
                    cost: data.primary_cost,
                    extra: data.secondary_cost,
                    minDays: data.min_delivery_days,
                    maxDays: data.max_delivery_days,
                }}
                onChange={(field, value) =>
                    setData(
                        {
                            cost: 'primary_cost',
                            extra: 'secondary_cost',
                            minDays: 'min_delivery_days',
                            maxDays: 'max_delivery_days',
                        }[field] as 'primary_cost',
                        value,
                    )
                }
                errors={{
                    cost: errors.primary_cost,
                    extra: errors.secondary_cost,
                    minDays: errors.min_delivery_days,
                    maxDays: errors.max_delivery_days,
                }}
            />
        </Shell>
    );
}

/** Um upgrade: a opção mais rápida que o comprador pode escolher. */
export function UpgradeDialog({
    open,
    onOpenChange,
    profile,
    editing,
}: DialogProps & {
    profile: ShippingProfile;
    editing: ShippingUpgrade | null;
}) {
    const { data, setData, post, processing, errors } = useForm({
        upgrade_id: editing?.id ?? null,
        upgrade_name: editing?.name ?? '',
        type: editing?.type ?? 'domestic',
        price: centsToInput(editing?.priceCents ?? null),
        secondary_price: editing
            ? centsToInput(editing.secondaryPriceCents ?? 0)
            : '0',
        min_delivery_days: daysToInput(editing?.minDays),
        max_delivery_days: daysToInput(editing?.maxDays),
    });

    const submit = () =>
        post(saveUpgrade(profile.id).url, {
            preserveScroll: true,
            onSuccess: (page) => !etsyRefused(page) && onOpenChange(false),
        });

    return (
        <Shell
            open={open}
            onOpenChange={onOpenChange}
            title={editing ? 'Editar upgrade' : 'Novo upgrade'}
            description={`A opção mais rápida que o comprador pode escolher no checkout. Perfil “${profile.title}”.`}
            onSubmit={submit}
            processing={processing}
            submitLabel="Guardar na Etsy"
            canSubmit={data.upgrade_name.trim() !== ''}
        >
            <div className="grid gap-3 sm:grid-cols-2">
                <Field
                    id="upgrade-name"
                    label="Nome"
                    hint="O que o comprador lê: “Expresso”."
                    error={errors.upgrade_name}
                >
                    <Input
                        id="upgrade-name"
                        value={data.upgrade_name}
                        maxLength={100}
                        autoFocus
                        onChange={(event) =>
                            setData('upgrade_name', event.target.value)
                        }
                    />
                </Field>
                <Field id="upgrade-type" label="Para" error={errors.type}>
                    <Select
                        value={data.type}
                        onValueChange={(value) =>
                            setData(
                                'type',
                                value as 'domestic' | 'international',
                            )
                        }
                    >
                        <SelectTrigger id="upgrade-type" className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="domestic">
                                Envios nacionais
                            </SelectItem>
                            <SelectItem value="international">
                                Envios internacionais
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </Field>
            </div>
            <CostAndDeliveryFields
                idPrefix="upgrade"
                labels={{
                    cost: 'Preço do upgrade',
                    extra: 'Por artigo adicional',
                }}
                values={{
                    cost: data.price,
                    extra: data.secondary_price,
                    minDays: data.min_delivery_days,
                    maxDays: data.max_delivery_days,
                }}
                onChange={(field, value) =>
                    setData(
                        {
                            cost: 'price',
                            extra: 'secondary_price',
                            minDays: 'min_delivery_days',
                            maxDays: 'max_delivery_days',
                        }[field] as 'price',
                        value,
                    )
                }
                errors={{
                    cost: errors.price,
                    extra: errors.secondary_price,
                    minDays: errors.min_delivery_days,
                    maxDays: errors.max_delivery_days,
                }}
            />
        </Shell>
    );
}

/** Quanto tempo leva uma peça a sair. */
export function ProcessingDialog({
    open,
    onOpenChange,
    editing,
}: DialogProps & { editing: ProcessingProfile | null }) {
    const { data, setData, post, processing, errors } = useForm({
        definition_id: editing?.id ?? null,
        readiness_state: editing?.state ?? 'made_to_order',
        min_processing_time: daysToInput(editing?.min),
        max_processing_time: daysToInput(editing?.max),
        processing_time_unit: editing?.unit ?? 'days',
    });

    const submit = () =>
        post(saveProcessing().url, {
            preserveScroll: true,
            onSuccess: (page) => !etsyRefused(page) && onOpenChange(false),
        });

    return (
        <Shell
            open={open}
            onOpenChange={onOpenChange}
            title={
                editing
                    ? 'Editar perfil de processamento'
                    : 'Novo perfil de processamento'
            }
            description={
                <>
                    <p>
                        Quanto tempo leva uma peça a sair de casa depois da
                        encomenda. A Etsy soma-o ao prazo de entrega.
                    </p>
                    {editing !== null && editing.activeListings > 0 && (
                        <p className="mt-1 text-warning">
                            Em uso por {editing.activeListings}{' '}
                            {editing.activeListings === 1
                                ? 'anúncio à venda'
                                : 'anúncios à venda'}
                            : mudar o prazo muda já a data de envio que os
                            compradores veem.
                        </p>
                    )}
                </>
            }
            onSubmit={submit}
            processing={processing}
            submitLabel="Guardar na Etsy"
        >
            <Field
                id="processing-state"
                label="Para peças"
                error={errors.readiness_state}
            >
                <Select
                    value={data.readiness_state}
                    onValueChange={(value) => setData('readiness_state', value)}
                >
                    <SelectTrigger id="processing-state" className="w-full">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {PROCESSING_STATES.map((state) => (
                            <SelectItem key={state.value} value={state.value}>
                                {state.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </Field>
            <div className="grid gap-3 sm:grid-cols-3">
                <Field
                    id="processing-min"
                    label="Mínimo"
                    error={errors.min_processing_time}
                >
                    <Input
                        id="processing-min"
                        type="number"
                        min={1}
                        value={data.min_processing_time}
                        onChange={(event) =>
                            setData('min_processing_time', event.target.value)
                        }
                    />
                </Field>
                <Field
                    id="processing-max"
                    label="Máximo"
                    error={errors.max_processing_time}
                >
                    <Input
                        id="processing-max"
                        type="number"
                        min={1}
                        value={data.max_processing_time}
                        onChange={(event) =>
                            setData('max_processing_time', event.target.value)
                        }
                    />
                </Field>
                <Field
                    id="processing-unit"
                    label="Unidade"
                    error={errors.processing_time_unit}
                >
                    <Select
                        value={data.processing_time_unit}
                        onValueChange={(value) =>
                            setData('processing_time_unit', value)
                        }
                    >
                        <SelectTrigger id="processing-unit" className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="days">Dias</SelectItem>
                            <SelectItem value="weeks">Semanas</SelectItem>
                        </SelectContent>
                    </Select>
                </Field>
            </div>
        </Shell>
    );
}

/** Se a loja aceita devoluções e trocas, e em quanto tempo. */
export function ReturnPolicyDialog({
    open,
    onOpenChange,
    editing,
    deadlines,
}: DialogProps & { editing: ReturnPolicy | null; deadlines: number[] }) {
    const { data, setData, post, processing, errors } = useForm({
        policy_id: editing?.id ?? null,
        accepts_returns: editing?.acceptsReturns ?? true,
        accepts_exchanges: editing?.acceptsExchanges ?? false,
        return_deadline: String(editing?.deadline ?? 14),
    });

    const accepts = data.accepts_returns || data.accepts_exchanges;

    const submit = () =>
        post(savePolicy().url, {
            preserveScroll: true,
            onSuccess: (page) => !etsyRefused(page) && onOpenChange(false),
        });

    return (
        <Shell
            open={open}
            onOpenChange={onOpenChange}
            title={
                editing
                    ? 'Editar política de devolução'
                    : 'Nova política de devolução'
            }
            description="Não é obrigatória para lojas da União Europeia, mas quem a tiver diz aos compradores com o que podem contar."
            onSubmit={submit}
            processing={processing}
            submitLabel="Guardar na Etsy"
        >
            <label className="flex items-center gap-2.5 text-sm">
                <Checkbox
                    checked={data.accepts_returns}
                    onCheckedChange={(checked) =>
                        setData('accepts_returns', checked === true)
                    }
                />
                Aceita devoluções
            </label>
            <label className="flex items-center gap-2.5 text-sm">
                <Checkbox
                    checked={data.accepts_exchanges}
                    onCheckedChange={(checked) =>
                        setData('accepts_exchanges', checked === true)
                    }
                />
                Aceita trocas
            </label>
            <InputError
                message={errors.accepts_returns ?? errors.accepts_exchanges}
            />

            {accepts && (
                <Field
                    id="policy-deadline"
                    label="Prazo"
                    error={errors.return_deadline}
                >
                    <Select
                        value={data.return_deadline}
                        onValueChange={(value) =>
                            setData('return_deadline', value)
                        }
                    >
                        <SelectTrigger id="policy-deadline" className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {deadlines.map((days) => (
                                <SelectItem key={days} value={String(days)}>
                                    {days} dias
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </Field>
            )}
        </Shell>
    );
}

/**
 * Dar o perfil a vários produtos de uma vez. O anúncio que já existe na Etsy
 * muda lá na hora; o produto que ainda não tem anúncio fica só com o perfil
 * escolhido, para quando for enviado.
 */
export function AssignDialog({
    open,
    onOpenChange,
    profile,
    products,
}: DialogProps & {
    profile: ShippingProfile;
    products: ShippingProductOption[];
}) {
    const [search, setSearch] = useState('');
    const { data, setData, post, processing, errors } = useForm<{
        product_ids: number[];
    }>({ product_ids: [] });

    const needle = search.trim().toLowerCase();
    const visible = products.filter(
        (product) =>
            needle === '' || product.name.toLowerCase().includes(needle),
    );

    const liveSelected = products.filter(
        (product) =>
            data.product_ids.includes(product.id) && product.state === 'active',
    ).length;

    const toggle = (id: number, checked: boolean) =>
        setData(
            'product_ids',
            checked
                ? [...data.product_ids, id]
                : data.product_ids.filter((selected) => selected !== id),
        );

    const submit = () =>
        post(assignProfile(profile.id).url, {
            preserveScroll: true,
            onSuccess: (page) => !etsyRefused(page) && onOpenChange(false),
        });

    return (
        <Shell
            open={open}
            onOpenChange={onOpenChange}
            title={`Aplicar “${profile.title}”`}
            description="Os anúncios que já estão na Etsy mudam lá na hora. Os produtos sem anúncio ficam com o perfil escolhido para quando forem enviados."
            onSubmit={submit}
            processing={processing}
            submitLabel={
                data.product_ids.length === 1
                    ? 'Aplicar a 1 produto'
                    : `Aplicar a ${data.product_ids.length} produtos`
            }
            canSubmit={data.product_ids.length > 0}
        >
            <Input
                type="search"
                value={search}
                placeholder="Procurar produto"
                aria-label="Procurar produto"
                onChange={(event) => setSearch(event.target.value)}
            />

            <ul className="max-h-72 overflow-y-auto rounded-lg border border-border/60">
                {visible.length === 0 && (
                    <li className="px-3 py-4 text-center text-sm text-muted-foreground">
                        Nenhum produto com esse nome.
                    </li>
                )}
                {visible.map((product) => {
                    const already = product.shippingProfileId === profile.id;

                    return (
                        <li
                            key={product.id}
                            className="border-b border-border/60 last:border-b-0"
                        >
                            <label className="flex items-center gap-2.5 px-3 py-2 text-sm">
                                <Checkbox
                                    checked={data.product_ids.includes(
                                        product.id,
                                    )}
                                    disabled={already}
                                    onCheckedChange={(checked) =>
                                        toggle(product.id, checked === true)
                                    }
                                />
                                <span className="min-w-0 flex-1 truncate">
                                    {product.name}
                                </span>
                                <span className="flex-none text-xs text-muted-foreground">
                                    {already
                                        ? 'já usa este perfil'
                                        : product.state === 'active'
                                          ? 'à venda na Etsy'
                                          : product.state === null
                                            ? 'sem anúncio'
                                            : 'na Etsy, por publicar'}
                                </span>
                            </label>
                        </li>
                    );
                })}
            </ul>

            {liveSelected > 0 && (
                <p className="text-sm text-warning">
                    {liveSelected === 1
                        ? '1 dos escolhidos está à venda'
                        : `${liveSelected} dos escolhidos estão à venda`}
                    : os compradores passam a ver os portes deste perfil
                    imediatamente.
                </p>
            )}
            <InputError message={errors.product_ids} />
        </Shell>
    );
}
