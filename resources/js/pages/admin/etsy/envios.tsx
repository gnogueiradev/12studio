import { Head, router, useForm } from '@inertiajs/react';
import { ShieldAlert } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { ConfirmDialog } from '@/components/admin/confirm-dialog';
import { EtsyNav } from '@/components/admin/etsy/etsy-nav';
import {
    AssignDialog,
    DestinationDialog,
    ProcessingDialog,
    ProfileDialog,
    ReturnPolicyDialog,
    UpgradeDialog,
} from '@/components/admin/etsy/shipping-dialogs';
import { PageHeader } from '@/components/admin/page-header';
import { Panel } from '@/components/admin/panel';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { formatCentsIn } from '@/lib/money';
import { index, predefinicoes } from '@/routes/admin/etsy/envios';
import { apagar as deleteDestination } from '@/routes/admin/etsy/envios/destinos';
import { apagar as deletePolicy } from '@/routes/admin/etsy/envios/devolucoes';
import { apagar as deleteProfile } from '@/routes/admin/etsy/envios/perfis';
import { apagar as deleteProcessing } from '@/routes/admin/etsy/envios/processamento';
import { apagar as deleteUpgrade } from '@/routes/admin/etsy/envios/upgrades';
import type {
    ProcessingProfile,
    ReturnPolicy,
    ShippingDefaults,
    ShippingDestination,
    ShippingProductOption,
    ShippingProfile,
    ShippingUpgrade,
} from '@/types/etsy';
import {
    deliveryLabel,
    destinationLabel,
    PROCESSING_STATES,
} from '@/types/etsy';

type Props = {
    connected: boolean;
    /** O que a Etsy respondeu quando não deixou ler a configuração. */
    error: string | null;
    profiles: ShippingProfile[];
    processingProfiles: ProcessingProfile[];
    returnPolicies: ReturnPolicy[];
    defaults: ShippingDefaults;
    /** Os prazos de devolução, em dias, que a Etsy aceita. */
    deadlines: number[];
    products: ShippingProductOption[];
};

/** O diálogo aberto, e sobre quê. Null = nenhum. */
type Open =
    | { kind: 'profile'; profile: ShippingProfile | null }
    | {
          kind: 'destination';
          profile: ShippingProfile;
          destination: ShippingDestination | null;
      }
    | {
          kind: 'upgrade';
          profile: ShippingProfile;
          upgrade: ShippingUpgrade | null;
      }
    | { kind: 'assign'; profile: ShippingProfile }
    | { kind: 'processing'; profile: ProcessingProfile | null }
    | { kind: 'policy'; policy: ReturnPolicy | null };

/** O que se está prestes a apagar da Etsy. */
type Deleting = { title: string; description: string; url: string };

function money(cents: number | null, currency: string | null): string {
    return cents === null ? '—' : formatCentsIn(cents, currency ?? 'EUR');
}

function plural(count: number, one: string, many: string): string {
    return `${count} ${count === 1 ? one : many}`;
}

function processingLabel(profile: ProcessingProfile): string {
    const state =
        PROCESSING_STATES.find((option) => option.value === profile.state)
            ?.label ?? profile.state;
    const unit = profile.unit === 'weeks' ? 'semanas' : 'dias';
    const time =
        profile.min === profile.max
            ? `${profile.min} ${unit}`
            : `${profile.min}–${profile.max} ${unit}`;

    return `${state} · ${time}`;
}

function policyLabel(policy: ReturnPolicy): string {
    if (!policy.acceptsReturns && !policy.acceptsExchanges) {
        return 'Não aceita devoluções nem trocas';
    }

    const what = [
        policy.acceptsReturns ? 'devoluções' : null,
        policy.acceptsExchanges ? 'trocas' : null,
    ]
        .filter((part) => part !== null)
        .join(' e ');

    return `Aceita ${what}${policy.deadline === null ? '' : ` até ${policy.deadline} dias`}`;
}

/**
 * Etsy → Envios: tudo o que a Etsy deixa gerir sobre envios, daqui.
 *
 * Nada disto fica guardado no 12studio — cada "guardar" vai direto à Etsy —
 * menos as predefinições, no fundo da página, que são escolhas nossas.
 */
export default function EtsyShipping({
    connected,
    error,
    profiles,
    processingProfiles,
    returnPolicies,
    defaults,
    deadlines,
    products,
}: Props) {
    const [open, setOpen] = useState<Open | null>(null);
    const [deleting, setDeleting] = useState<Deleting | null>(null);
    const [busy, setBusy] = useState(false);

    const close = (isOpen: boolean) => {
        if (!isOpen) {
            setOpen(null);
        }
    };

    const confirmDelete = () => {
        if (deleting === null) {
            return;
        }

        setBusy(true);
        router.delete(deleting.url, {
            preserveScroll: true,
            onFinish: () => {
                setBusy(false);
                setDeleting(null);
            },
        });
    };

    return (
        <>
            <Head title="Envios na Etsy" />
            <div className="flex h-full w-full max-w-[1100px] flex-1 flex-col p-6 pb-10">
                <PageHeader
                    title="Envios na Etsy"
                    description="Perfis de envio, tempos de processamento e devoluções. O que guardares aqui vai direto à Etsy."
                />
                <EtsyNav current="envios" />

                {!connected && (
                    <Alert className="mt-5 max-w-3xl">
                        <ShieldAlert />
                        <AlertTitle>Ainda não há loja ligada</AlertTitle>
                        <AlertDescription>
                            Liga a loja em Definições → Etsy para gerir os
                            envios daqui.
                        </AlertDescription>
                    </Alert>
                )}

                {error !== null && (
                    <Alert variant="destructive" className="mt-5 max-w-3xl">
                        <ShieldAlert />
                        <AlertTitle>
                            Não foi possível ler os envios da Etsy
                        </AlertTitle>
                        <AlertDescription>{error}</AlertDescription>
                    </Alert>
                )}

                {connected && error === null && (
                    <div className="mt-6 flex flex-col gap-8">
                        <Section
                            title="Perfis de envio"
                            description="Cada anúncio usa um. Os custos vivem nos destinos de cada perfil."
                            action={
                                <Button
                                    size="sm"
                                    onClick={() =>
                                        setOpen({
                                            kind: 'profile',
                                            profile: null,
                                        })
                                    }
                                >
                                    Novo perfil
                                </Button>
                            }
                        >
                            {profiles.length === 0 && (
                                <Empty>
                                    A loja ainda não tem perfis de envio. Sem
                                    um, não se publica nenhum anúncio.
                                </Empty>
                            )}
                            {profiles.map((profile) => (
                                <ProfileCard
                                    key={profile.id}
                                    profile={profile}
                                    onOpen={setOpen}
                                    onDelete={setDeleting}
                                />
                            ))}
                        </Section>

                        <Section
                            title="Perfis de processamento"
                            description="Quanto tempo leva uma peça a sair, conforme esteja pronta ou seja feita por encomenda."
                            action={
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() =>
                                        setOpen({
                                            kind: 'processing',
                                            profile: null,
                                        })
                                    }
                                >
                                    Novo
                                </Button>
                            }
                        >
                            {processingProfiles.length === 0 && (
                                <Empty>Nenhum perfil de processamento.</Empty>
                            )}
                            {processingProfiles.length > 0 && (
                                <ul className="rounded-xl border border-border/60 bg-card">
                                    {processingProfiles.map((profile) => (
                                        <Row
                                            key={profile.id}
                                            label={processingLabel(profile)}
                                            note={
                                                profile.defaultFor === null
                                                    ? null
                                                    : 'predefinição'
                                            }
                                            onEdit={() =>
                                                setOpen({
                                                    kind: 'processing',
                                                    profile,
                                                })
                                            }
                                            onDelete={() =>
                                                setDeleting({
                                                    title: 'Apagar o perfil de processamento',
                                                    description: `“${processingLabel(profile)}” desaparece da Etsy. Se for a predefinição ou estiver em uso por um anúncio, não se apaga.`,
                                                    url: deleteProcessing(
                                                        profile.id,
                                                    ).url,
                                                })
                                            }
                                        />
                                    ))}
                                </ul>
                            )}
                        </Section>

                        <Section
                            title="Políticas de devolução"
                            description="Opcionais para lojas da União Europeia."
                            action={
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() =>
                                        setOpen({
                                            kind: 'policy',
                                            policy: null,
                                        })
                                    }
                                >
                                    Nova
                                </Button>
                            }
                        >
                            {returnPolicies.length === 0 && (
                                <Empty>Nenhuma política de devolução.</Empty>
                            )}
                            {returnPolicies.length > 0 && (
                                <ul className="rounded-xl border border-border/60 bg-card">
                                    {returnPolicies.map((policy) => (
                                        <Row
                                            key={policy.id}
                                            label={policyLabel(policy)}
                                            note={
                                                [
                                                    policy.isDefault
                                                        ? 'predefinição'
                                                        : null,
                                                    policy.listings > 0
                                                        ? plural(
                                                              policy.listings,
                                                              'anúncio',
                                                              'anúncios',
                                                          )
                                                        : null,
                                                ]
                                                    .filter(
                                                        (part) => part !== null,
                                                    )
                                                    .join(' · ') || null
                                            }
                                            onEdit={() =>
                                                setOpen({
                                                    kind: 'policy',
                                                    policy,
                                                })
                                            }
                                            onDelete={() =>
                                                setDeleting({
                                                    title: 'Apagar a política de devolução',
                                                    description: `“${policyLabel(policy)}” desaparece da Etsy. Se algum anúncio a usar, não se apaga.`,
                                                    url: deletePolicy(policy.id)
                                                        .url,
                                                })
                                            }
                                        />
                                    ))}
                                </ul>
                            )}
                        </Section>

                        <DefaultsForm
                            // Remonta quando as escolhas ou as opções mudam.
                            key={JSON.stringify([
                                defaults,
                                profiles.map((profile) => profile.id),
                                processingProfiles.map((profile) => profile.id),
                                returnPolicies.map((policy) => policy.id),
                            ])}
                            defaults={defaults}
                            profiles={profiles}
                            processingProfiles={processingProfiles}
                            returnPolicies={returnPolicies}
                        />
                    </div>
                )}
            </div>

            {open?.kind === 'profile' && (
                <ProfileDialog
                    key={`profile-${open.profile?.id ?? 'new'}`}
                    open
                    onOpenChange={close}
                    editing={open.profile}
                />
            )}
            {open?.kind === 'destination' && (
                <DestinationDialog
                    key={`destination-${open.destination?.id ?? 'new'}`}
                    open
                    onOpenChange={close}
                    profile={open.profile}
                    editing={open.destination}
                />
            )}
            {open?.kind === 'upgrade' && (
                <UpgradeDialog
                    key={`upgrade-${open.upgrade?.id ?? 'new'}`}
                    open
                    onOpenChange={close}
                    profile={open.profile}
                    editing={open.upgrade}
                />
            )}
            {open?.kind === 'assign' && (
                <AssignDialog
                    key={`assign-${open.profile.id}`}
                    open
                    onOpenChange={close}
                    profile={open.profile}
                    products={products}
                />
            )}
            {open?.kind === 'processing' && (
                <ProcessingDialog
                    key={`processing-${open.profile?.id ?? 'new'}`}
                    open
                    onOpenChange={close}
                    editing={open.profile}
                />
            )}
            {open?.kind === 'policy' && (
                <ReturnPolicyDialog
                    key={`policy-${open.policy?.id ?? 'new'}`}
                    open
                    onOpenChange={close}
                    editing={open.policy}
                    deadlines={deadlines}
                />
            )}

            <ConfirmDialog
                open={deleting !== null}
                onOpenChange={(isOpen) => !isOpen && setDeleting(null)}
                title={deleting?.title ?? ''}
                description={
                    <>
                        <p>{deleting?.description}</p>
                        <p className="mt-2">
                            Na Etsy isto não se recupera — só voltando a criar.
                        </p>
                    </>
                }
                confirmLabel="Apagar da Etsy"
                destructive
                processing={busy}
                onConfirm={confirmDelete}
            />
        </>
    );
}

function Section({
    title,
    description,
    action,
    children,
}: {
    title: string;
    description: string;
    action: ReactNode;
    children: ReactNode;
}) {
    return (
        <section className="flex flex-col gap-3">
            <div className="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 className="text-[15px] font-semibold">{title}</h2>
                    <p className="mt-0.5 text-sm text-muted-foreground">
                        {description}
                    </p>
                </div>
                {action}
            </div>
            {children}
        </section>
    );
}

function Empty({ children }: { children: ReactNode }) {
    return (
        <p className="rounded-xl border border-dashed border-border px-4 py-6 text-center text-sm text-muted-foreground">
            {children}
        </p>
    );
}

function Row({
    label,
    note,
    onEdit,
    onDelete,
}: {
    label: string;
    note: string | null;
    onEdit: () => void;
    onDelete: () => void;
}) {
    return (
        <li className="flex flex-wrap items-center gap-x-3 gap-y-1.5 border-b border-border/60 px-4 py-2.5 text-sm last:border-b-0">
            <span className="min-w-0 flex-1">{label}</span>
            {note !== null && (
                <span className="text-xs text-muted-foreground">{note}</span>
            )}
            <span className="flex flex-none gap-1">
                <Button variant="ghost" size="sm" onClick={onEdit}>
                    Editar
                </Button>
                <Button
                    variant="ghost"
                    size="sm"
                    onClick={onDelete}
                    aria-label={`Apagar: ${label}`}
                >
                    Apagar
                </Button>
            </span>
        </li>
    );
}

function ProfileCard({
    profile,
    onOpen,
    onDelete,
}: {
    profile: ShippingProfile;
    onOpen: (open: Open) => void;
    onDelete: (deleting: Deleting) => void;
}) {
    const usage =
        profile.listings === 0
            ? 'nenhum anúncio o usa'
            : `${plural(profile.listings, 'anúncio', 'anúncios')}${
                  profile.activeListings > 0
                      ? `, ${profile.activeListings} à venda`
                      : ''
              }`;

    return (
        <Panel
            title={profile.title}
            description={`Sai de ${profile.originCountry ?? '—'}${
                profile.originPostalCode ? ` (${profile.originPostalCode})` : ''
            } · ${usage}${profile.isDefault ? ' · predefinição' : ''}`}
            aside={
                <span className="flex flex-wrap justify-end gap-1">
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => onOpen({ kind: 'assign', profile })}
                    >
                        Aplicar a produtos
                    </Button>
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => onOpen({ kind: 'profile', profile })}
                    >
                        Editar
                    </Button>
                    <Button
                        variant="ghost"
                        size="sm"
                        aria-label={`Apagar o perfil ${profile.title}`}
                        onClick={() =>
                            onDelete({
                                title: `Apagar o perfil “${profile.title}”`,
                                description:
                                    profile.listings > 0
                                        ? 'Há anúncios a usar este perfil: enquanto assim for, não se apaga. Dá-lhes outro perfil primeiro.'
                                        : 'O perfil, os destinos e os upgrades dele desaparecem da Etsy.',
                                url: deleteProfile(profile.id).url,
                            })
                        }
                    >
                        Apagar
                    </Button>
                </span>
            }
        >
            <div className="flex flex-col gap-4">
                <div>
                    <SubHeader
                        title="Destinos"
                        action="Novo destino"
                        onAction={() =>
                            onOpen({
                                kind: 'destination',
                                profile,
                                destination: null,
                            })
                        }
                    />
                    {profile.destinations.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            Sem destinos: ninguém consegue comprar com este
                            perfil.
                        </p>
                    ) : (
                        <ul className="rounded-lg border border-border/60">
                            {profile.destinations.map((destination) => (
                                <Row
                                    key={destination.id}
                                    label={`${destinationLabel(destination)} · ${money(
                                        destination.primaryCostCents,
                                        destination.currency,
                                    )} (+${money(
                                        destination.secondaryCostCents,
                                        destination.currency,
                                    )} por artigo adicional)`}
                                    note={deliveryLabel(
                                        destination.minDays,
                                        destination.maxDays,
                                    )}
                                    onEdit={() =>
                                        onOpen({
                                            kind: 'destination',
                                            profile,
                                            destination,
                                        })
                                    }
                                    onDelete={() =>
                                        onDelete({
                                            title: `Apagar o destino ${destinationLabel(destination)}`,
                                            description: `Os anúncios com o perfil “${profile.title}” deixam de enviar para ${destinationLabel(destination)}.`,
                                            url: deleteDestination({
                                                profile: profile.id,
                                                destination: destination.id,
                                            }).url,
                                        })
                                    }
                                />
                            ))}
                        </ul>
                    )}
                </div>

                <div>
                    <SubHeader
                        title="Upgrades"
                        action="Novo upgrade"
                        onAction={() =>
                            onOpen({ kind: 'upgrade', profile, upgrade: null })
                        }
                    />
                    {profile.upgrades.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            Sem upgrades: o comprador só tem o envio normal.
                        </p>
                    ) : (
                        <ul className="rounded-lg border border-border/60">
                            {profile.upgrades.map((upgrade) => (
                                <Row
                                    key={upgrade.id}
                                    label={`${upgrade.name} · ${money(
                                        upgrade.priceCents,
                                        upgrade.currency,
                                    )} · ${
                                        upgrade.type === 'international'
                                            ? 'internacional'
                                            : 'nacional'
                                    }`}
                                    note={
                                        upgrade.minDays === null
                                            ? null
                                            : deliveryLabel(
                                                  upgrade.minDays,
                                                  upgrade.maxDays,
                                              )
                                    }
                                    onEdit={() =>
                                        onOpen({
                                            kind: 'upgrade',
                                            profile,
                                            upgrade,
                                        })
                                    }
                                    onDelete={() =>
                                        onDelete({
                                            title: `Apagar o upgrade “${upgrade.name}”`,
                                            description: `Deixa de aparecer no checkout dos anúncios com o perfil “${profile.title}”.`,
                                            url: deleteUpgrade({
                                                profile: profile.id,
                                                upgrade: upgrade.id,
                                            }).url,
                                        })
                                    }
                                />
                            ))}
                        </ul>
                    )}
                </div>
            </div>
        </Panel>
    );
}

function SubHeader({
    title,
    action,
    onAction,
}: {
    title: string;
    action: string;
    onAction: () => void;
}) {
    return (
        <div className="mb-2 flex items-center justify-between gap-3">
            <h3 className="text-xs font-medium tracking-wider text-muted-foreground uppercase">
                {title}
            </h3>
            <Button variant="ghost" size="sm" onClick={onAction}>
                {action}
            </Button>
        </div>
    );
}

/** O valor que o Select usa para "nenhum" (o Radix não aceita ''). */
const NONE = 'none';

function toChoice(id: number | null): string {
    return id === null ? NONE : String(id);
}

function fromChoice(choice: string): number | null {
    return choice === NONE ? null : Number(choice);
}

/**
 * As predefinições: o que leva um anúncio que não escolheu nada. São escolhas
 * nossas — gravam-se no 12studio e não escrevem nada na Etsy.
 */
function DefaultsForm({
    defaults,
    profiles,
    processingProfiles,
    returnPolicies,
}: {
    defaults: ShippingDefaults;
    profiles: ShippingProfile[];
    processingProfiles: ProcessingProfile[];
    returnPolicies: ReturnPolicy[];
}) {
    const { data, setData, patch, processing, isDirty } = useForm({
        default_shipping_profile_id: defaults.shippingProfileId,
        ready_to_ship_profile_id: defaults.readyToShipProfileId,
        made_to_order_profile_id: defaults.madeToOrderProfileId,
        default_return_policy_id: defaults.returnPolicyId,
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        patch(predefinicoes().url, { preserveScroll: true });
    };

    const processingOptions = (state: string) =>
        processingProfiles
            .filter((profile) => profile.state === state)
            .map((profile) => ({
                value: String(profile.id),
                label: processingLabel(profile),
            }));

    return (
        <Panel
            title="Predefinições"
            description="O que leva um anúncio enviado daqui quando o produto não escolheu outra coisa. Fica guardado no 12studio; não muda nada na Etsy."
        >
            <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                <Choice
                    id="default-shipping"
                    label="Perfil de envio"
                    value={toChoice(data.default_shipping_profile_id)}
                    onChange={(choice) =>
                        setData(
                            'default_shipping_profile_id',
                            fromChoice(choice),
                        )
                    }
                    options={profiles.map((profile) => ({
                        value: String(profile.id),
                        label: profile.title,
                    }))}
                />
                <Choice
                    id="default-policy"
                    label="Política de devolução"
                    value={toChoice(data.default_return_policy_id)}
                    onChange={(choice) =>
                        setData('default_return_policy_id', fromChoice(choice))
                    }
                    options={returnPolicies.map((policy) => ({
                        value: String(policy.id),
                        label: policyLabel(policy),
                    }))}
                />
                <Choice
                    id="default-ready"
                    label="Processamento — peças em stock"
                    value={toChoice(data.ready_to_ship_profile_id)}
                    onChange={(choice) =>
                        setData('ready_to_ship_profile_id', fromChoice(choice))
                    }
                    options={processingOptions('ready_to_ship')}
                />
                <Choice
                    id="default-mto"
                    label="Processamento — feitas por encomenda"
                    value={toChoice(data.made_to_order_profile_id)}
                    onChange={(choice) =>
                        setData('made_to_order_profile_id', fromChoice(choice))
                    }
                    options={processingOptions('made_to_order')}
                />
                <div className="flex items-center gap-3 sm:col-span-2">
                    <Button type="submit" disabled={processing || !isDirty}>
                        {processing && <Spinner />}
                        Guardar predefinições
                    </Button>
                    {isDirty && (
                        <span className="text-sm text-warning">
                            Alterações por guardar.
                        </span>
                    )}
                </div>
            </form>
        </Panel>
    );
}

function Choice({
    id,
    label,
    value,
    onChange,
    options,
}: {
    id: string;
    label: string;
    value: string;
    onChange: (choice: string) => void;
    options: { value: string; label: string }[];
}) {
    // Uma predefinição que aponta para algo que já não existe na Etsy mostra-se
    // como "nenhum" em vez de um seletor vazio.
    const known =
        value === NONE || options.some((option) => option.value === value);

    return (
        <div className="grid gap-1.5">
            <label htmlFor={id} className="text-sm font-medium">
                {label}
            </label>
            <Select value={known ? value : NONE} onValueChange={onChange}>
                <SelectTrigger id={id} className="w-full">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value={NONE}>Nenhum</SelectItem>
                    {options.map((option) => (
                        <SelectItem key={option.value} value={option.value}>
                            {option.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </div>
    );
}

EtsyShipping.layout = {
    breadcrumbs: [
        { title: 'Backoffice', href: '/admin' },
        { title: 'Etsy', href: index() },
        { title: 'Envios', href: index() },
    ],
};
