import { Head, Link, useForm } from '@inertiajs/react';
import { ExternalLink } from 'lucide-react';
import { useState } from 'react';
import { LabelTranslations } from '@/components/admin/label-translations';
import { MontraNav } from '@/components/admin/montra/montra-nav';
import { PageHeader } from '@/components/admin/page-header';
import { Panel } from '@/components/admin/panel';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import type { Option } from '@/lib/options';
import { cn } from '@/lib/utils';
import { home } from '@/routes';
import { index as settingsIndex } from '@/routes/admin/definicoes';
import { update } from '@/routes/admin/montra';

/** A faixa de aviso do topo da montra (StoreSettings::KEY_ANNOUNCEMENT). */
type AnnouncementForm = {
    enabled: boolean;
    /** Por idioma; todos presentes, vazios quando não há texto. */
    text: Record<string, string>;
    /** Um caminho da montra ("/envios-e-devolucoes"), https://… ou vazio. */
    link: string;
};

/** As ofertas para voltar a comprar (StoreSettings::KEY_OFFERS). */
type OffersForm = {
    enabled: boolean;
    percent: number;
    days_after_shipping: number;
    valid_days: number;
    reminder_days: number;
};

/** Os campos numéricos das ofertas, com os limites do pedido. */
const OFFER_FIELDS: {
    key: Exclude<keyof OffersForm, 'enabled'>;
    label: string;
    min: number;
    max: number;
}[] = [
    { key: 'percent', label: 'Desconto (%)', min: 1, max: 50 },
    {
        key: 'days_after_shipping',
        label: 'Dias depois do envio',
        min: 1,
        max: 120,
    },
    { key: 'valid_days', label: 'Validade do código (dias)', min: 7, max: 365 },
    {
        key: 'reminder_days',
        label: 'Lembrete (dias antes de expirar)',
        min: 1,
        max: 30,
    },
];

/** O mesmo limite do StoreSettings::ANNOUNCEMENT_MAX. */
const ANNOUNCEMENT_MAX = 160;

/** Os mesmos modos do StoreSettings::SHOP_MODES. */
const SHOP_MODES = [
    { value: 'off', label: 'Desligadas' },
    { value: 'staff', label: 'Só para a equipa' },
    { value: 'on', label: 'Ligadas' },
];

type StoreForm = {
    open: boolean;
    shop: string;
    contact_email: string;
    phone: string;
    address: string;
    opening_hours: string;
    response_hours: number | null;
    social: Record<string, string>;
    locales: string[];
    announcement: AnnouncementForm;
    offers: OffersForm;
};

type Props = {
    store: StoreForm;
    networks: Option[];
    languages: (Option & { locked: boolean })[];
    /** ShopGate::ready(): Stripe e segredo do webhook configurados. */
    shopReady: boolean;
    /** ShopGate::testMode(): produção com uma chave de teste do Stripe. */
    shopTestMode: boolean;
};

/**
 * Definições → Montra. Abrir a loja ao público e os contactos que ela mostra
 * (rodapé, página de contacto, botão "Fala connosco"). Um só formulário: são
 * poucos campos e mudam juntos quando se prepara o lançamento.
 */
export default function StoreSettingsPage({
    store,
    networks,
    languages,
    shopReady,
    shopTestMode,
}: Props) {
    const { data, setData, patch, processing, errors } = useForm<StoreForm>({
        ...store,
    });

    const [saved, setSaved] = useState(false);

    // Contra as props e nao contra os valores iniciais do useForm: depois de
    // gravar, as props trazem o estado novo e o formulario volta a "limpo".
    const dirty = JSON.stringify(data) !== JSON.stringify(store);

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        patch(update().url, {
            preserveScroll: true,
            onSuccess: () => setSaved(true),
        });
    };

    const change = <K extends keyof StoreForm>(key: K, value: StoreForm[K]) => {
        setSaved(false);
        setData((current) => ({ ...current, [key]: value }));
    };

    const toggleLocale = (code: string, on: boolean) => {
        setSaved(false);
        setData((current) => ({
            ...current,
            // Pela ordem da lista e não pela dos cliques: assim o "por
            // guardar" não acende só por se desligar e voltar a ligar.
            locales: languages
                .map((language) => language.value)
                .filter((value) =>
                    value === code ? on : current.locales.includes(value),
                ),
        }));
    };

    const changeSocial = (network: string, value: string) => {
        setSaved(false);
        setData((current) => ({
            ...current,
            social: { ...current.social, [network]: value },
        }));
    };

    const changeAnnouncement = (value: Partial<AnnouncementForm>) => {
        setSaved(false);
        setData((current) => ({
            ...current,
            announcement: { ...current.announcement, ...value },
        }));
    };

    const changeOffers = (value: Partial<OffersForm>) => {
        setSaved(false);
        setData((current) => ({
            ...current,
            offers: { ...current.offers, ...value },
        }));
    };

    const status = saved
        ? { text: 'Guardado.', className: 'text-success' }
        : dirty
          ? { text: 'Alterações por guardar.', className: 'text-warning' }
          : {
                text: 'Sem alterações por guardar.',
                className: 'text-muted-foreground',
            };

    // O erro de um campo aninhado chega como "social.instagram".
    const fieldErrors = errors as Record<string, string | undefined>;

    return (
        <>
            <Head title="Montra" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Montra"
                    description="O site público: se está aberto e como é que os clientes falam contigo."
                >
                    <Button variant="outline" asChild>
                        <Link href={settingsIndex()}>Definições</Link>
                    </Button>
                    <Button variant="outline" asChild>
                        <a href={home().url} target="_blank" rel="noreferrer">
                            <ExternalLink />
                            Ver montra
                        </a>
                    </Button>
                </PageHeader>
                <MontraNav current="definicoes" />

                <form
                    onSubmit={submit}
                    className="flex max-w-2xl flex-col gap-4"
                >
                    <Panel
                        title="Estado"
                        description={
                            store.open
                                ? 'A montra está aberta: qualquer visitante vê o catálogo.'
                                : 'A montra está fechada: os visitantes veem a página "em breve". Tu, com sessão de admin, vês a montra com um aviso por cima.'
                        }
                    >
                        <Label className="flex items-start gap-3 font-normal">
                            <Checkbox
                                checked={data.open}
                                onCheckedChange={(checked) =>
                                    change('open', checked === true)
                                }
                                className="mt-0.5"
                            />
                            <span className="grid gap-1">
                                <span className="font-medium">
                                    Montra aberta ao público
                                </span>
                                <span className="text-sm text-muted-foreground">
                                    Desmarcar volta a pôr a página "em breve"
                                    para quem visita. Nada se apaga.
                                </span>
                            </span>
                        </Label>
                        <InputError message={errors.open} />

                        <div className="grid gap-2">
                            <Label htmlFor="shop">Compras na montra</Label>
                            <Select
                                value={data.shop}
                                onValueChange={(value) => change('shop', value)}
                            >
                                <SelectTrigger id="shop" className="w-64">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {SHOP_MODES.map((mode) => (
                                        <SelectItem
                                            key={mode.value}
                                            value={mode.value}
                                        >
                                            {mode.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.shop} />
                            {!shopReady && (
                                <p className="text-sm text-muted-foreground">
                                    O Stripe ou o segredo do webhook não estão
                                    configurados: as compras ficam desligadas
                                    seja qual for a escolha.
                                </p>
                            )}
                            {shopTestMode && (
                                <p className="text-sm text-muted-foreground">
                                    O Stripe está em modo de teste: as compras
                                    só funcionam para a equipa, mesmo com
                                    «Ligadas» escolhido.
                                </p>
                            )}
                        </div>
                    </Panel>

                    <Panel
                        title="Faixa de aviso"
                        description="Uma linha por cima do cabeçalho, em todas as páginas da montra: portes grátis, uma promoção, as férias do estúdio."
                    >
                        <div className="grid gap-4">
                            <Label className="flex items-start gap-3 font-normal">
                                <Checkbox
                                    checked={data.announcement.enabled}
                                    onCheckedChange={(checked) =>
                                        changeAnnouncement({
                                            enabled: checked === true,
                                        })
                                    }
                                    className="mt-0.5"
                                />
                                <span className="grid gap-1">
                                    <span className="font-medium">
                                        Mostrar a faixa
                                    </span>
                                    <span className="text-sm text-muted-foreground">
                                        Desmarcar tira-a da montra. O texto fica
                                        guardado para a próxima vez.
                                    </span>
                                </span>
                            </Label>

                            <div className="grid gap-2">
                                <Label htmlFor="announcement_text_pt">
                                    Texto
                                </Label>
                                <Input
                                    id="announcement_text_pt"
                                    value={data.announcement.text.pt ?? ''}
                                    maxLength={ANNOUNCEMENT_MAX}
                                    placeholder="Portes grátis em encomendas acima de 25 €"
                                    onChange={(event) =>
                                        changeAnnouncement({
                                            text: {
                                                ...data.announcement.text,
                                                pt: event.target.value,
                                            },
                                        })
                                    }
                                />
                                <InputError
                                    message={
                                        fieldErrors['announcement.text.pt']
                                    }
                                />
                            </div>

                            <LabelTranslations
                                idPrefix="announcement_text"
                                legend="Texto noutros idiomas"
                                maxLength={ANNOUNCEMENT_MAX}
                                stacked
                                value={data.announcement.text}
                                onChange={(text) =>
                                    changeAnnouncement({ text })
                                }
                            />

                            <div className="grid gap-2">
                                <Label htmlFor="announcement_link">
                                    Link (opcional)
                                </Label>
                                <Input
                                    id="announcement_link"
                                    value={data.announcement.link}
                                    maxLength={255}
                                    placeholder="/envios-e-devolucoes"
                                    onChange={(event) =>
                                        changeAnnouncement({
                                            link: event.target.value,
                                        })
                                    }
                                />
                                <p className="text-xs text-muted-foreground">
                                    Uma página da montra, começada por / e sem o
                                    idioma à frente (/en, /es…): fica sozinha no
                                    idioma de quem visita. Ou um endereço
                                    https://. Vazio, a faixa é só texto.
                                </p>
                                <InputError
                                    message={fieldErrors['announcement.link']}
                                />
                            </div>
                        </div>
                    </Panel>

                    <Panel
                        title="Ofertas para voltar a comprar"
                        description="Uns dias depois do envio, quem comprou recebe um código pessoal de desconto por email, e um lembrete antes de ele expirar."
                    >
                        <div className="grid gap-4">
                            <Label className="flex items-start gap-3 font-normal">
                                <Checkbox
                                    checked={data.offers.enabled}
                                    onCheckedChange={(checked) =>
                                        changeOffers({
                                            enabled: checked === true,
                                        })
                                    }
                                    className="mt-0.5"
                                />
                                <span className="grid gap-1">
                                    <span className="font-medium">
                                        Mandar as ofertas
                                    </span>
                                    <span className="text-sm text-muted-foreground">
                                        Só saem com as compras ligadas para o
                                        público.
                                    </span>
                                </span>
                            </Label>
                            <InputError
                                message={fieldErrors['offers.enabled']}
                            />

                            <div className="grid gap-4 sm:grid-cols-2">
                                {OFFER_FIELDS.map((field) => (
                                    <div key={field.key} className="grid gap-2">
                                        <Label htmlFor={`offers_${field.key}`}>
                                            {field.label}
                                        </Label>
                                        <Input
                                            id={`offers_${field.key}`}
                                            type="number"
                                            inputMode="numeric"
                                            min={field.min}
                                            max={field.max}
                                            value={data.offers[field.key]}
                                            onChange={(event) =>
                                                changeOffers({
                                                    [field.key]: Number(
                                                        event.target.value,
                                                    ),
                                                })
                                            }
                                        />
                                        <InputError
                                            message={
                                                fieldErrors[
                                                    `offers.${field.key}`
                                                ]
                                            }
                                        />
                                    </div>
                                ))}
                            </div>

                            <p className="text-sm text-muted-foreground">
                                A lei só deixa mandar estas ofertas a quem
                                comprou no checkout da loja com a caixa «Não
                                quero receber ofertas» por marcar. Encomendas
                                feitas no backoffice, pagas por link ou de antes
                                da caixa nunca recebem, nem quem recusou. Cada
                                email leva um link para deixar de receber.
                            </p>
                        </div>
                    </Panel>

                    <Panel
                        title="Contactos"
                        description="Aparecem no rodapé, na página de contacto e no botão “Fala connosco”. Os campos vazios não se mostram."
                    >
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="contact_email">Email</Label>
                                <Input
                                    id="contact_email"
                                    type="email"
                                    value={data.contact_email}
                                    maxLength={120}
                                    placeholder="ola@aminhaloja.pt"
                                    onChange={(event) =>
                                        change(
                                            'contact_email',
                                            event.target.value,
                                        )
                                    }
                                />
                                <InputError message={errors.contact_email} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="phone">Telefone</Label>
                                <Input
                                    id="phone"
                                    type="tel"
                                    value={data.phone}
                                    maxLength={30}
                                    placeholder="+351 912 345 678"
                                    onChange={(event) =>
                                        change('phone', event.target.value)
                                    }
                                />
                                <InputError message={errors.phone} />
                            </div>
                            <div className="grid gap-2 sm:col-span-2">
                                <Label htmlFor="address">Morada</Label>
                                <Input
                                    id="address"
                                    value={data.address}
                                    maxLength={200}
                                    onChange={(event) =>
                                        change('address', event.target.value)
                                    }
                                />
                                <InputError message={errors.address} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="opening_hours">Horário</Label>
                                <Input
                                    id="opening_hours"
                                    value={data.opening_hours}
                                    maxLength={120}
                                    placeholder="Seg–Sex, 10h–18h"
                                    onChange={(event) =>
                                        change(
                                            'opening_hours',
                                            event.target.value,
                                        )
                                    }
                                />
                                <InputError message={errors.opening_hours} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="response_hours">
                                    Tempo de resposta (horas)
                                </Label>
                                <Input
                                    id="response_hours"
                                    type="number"
                                    inputMode="numeric"
                                    min={1}
                                    max={168}
                                    value={data.response_hours ?? ''}
                                    placeholder="24"
                                    onChange={(event) =>
                                        change(
                                            'response_hours',
                                            event.target.value === ''
                                                ? null
                                                : Number(event.target.value),
                                        )
                                    }
                                />
                                <InputError message={errors.response_hours} />
                            </div>
                        </div>
                        {data.response_hours !== null && (
                            <p className="mt-4 text-sm text-muted-foreground">
                                Na montra: “Respondemos normalmente em{' '}
                                {data.response_hours}{' '}
                                {data.response_hours === 1 ? 'hora' : 'horas'}.”
                            </p>
                        )}
                    </Panel>

                    <Panel
                        title="Idiomas"
                        description="Em que línguas a montra está disponível. O português é o da raiz do site e não se desliga; os outros ficam em /es, /en e /fr."
                    >
                        <div className="flex flex-wrap gap-x-6 gap-y-3">
                            {languages.map((language) => (
                                <Label
                                    key={language.value}
                                    className="flex items-center gap-2 font-normal"
                                >
                                    <Checkbox
                                        checked={data.locales.includes(
                                            language.value,
                                        )}
                                        disabled={language.locked}
                                        onCheckedChange={(checked) =>
                                            toggleLocale(
                                                language.value,
                                                checked === true,
                                            )
                                        }
                                    />
                                    {language.label}
                                </Label>
                            ))}
                        </div>
                        <InputError message={errors.locales} />
                    </Panel>

                    <Panel
                        title="Redes sociais"
                        description="O link completo do perfil, começado por https://. Os campos vazios não se mostram."
                    >
                        <div className="grid gap-4 sm:grid-cols-2">
                            {networks.map((network) => (
                                <div key={network.value} className="grid gap-2">
                                    <Label htmlFor={`social_${network.value}`}>
                                        {network.label}
                                    </Label>
                                    <Input
                                        id={`social_${network.value}`}
                                        type="url"
                                        value={data.social[network.value] ?? ''}
                                        maxLength={255}
                                        placeholder="https://"
                                        onChange={(event) =>
                                            changeSocial(
                                                network.value,
                                                event.target.value,
                                            )
                                        }
                                    />
                                    <InputError
                                        message={
                                            fieldErrors[
                                                `social.${network.value}`
                                            ]
                                        }
                                    />
                                </div>
                            ))}
                        </div>
                    </Panel>

                    <div className="flex flex-wrap items-center gap-3">
                        <Button type="submit" disabled={!dirty || processing}>
                            {processing && <Spinner />}
                            Guardar
                        </Button>
                        <p
                            role="status"
                            className={cn('text-sm', status.className)}
                        >
                            {status.text}
                        </p>
                    </div>
                </form>
            </div>
        </>
    );
}
