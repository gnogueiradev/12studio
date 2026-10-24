import { Head, Link, useForm } from '@inertiajs/react';
import { ExternalLink } from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@/components/admin/page-header';
import { Panel } from '@/components/admin/panel';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { Option } from '@/lib/options';
import { cn } from '@/lib/utils';
import { home } from '@/routes';
import { index as settingsIndex } from '@/routes/admin/definicoes';
import { update } from '@/routes/admin/montra';

type StoreForm = {
    open: boolean;
    contact_email: string;
    phone: string;
    address: string;
    opening_hours: string;
    response_hours: number | null;
    social: Record<string, string>;
};

type Props = {
    store: StoreForm;
    networks: Option[];
};

/**
 * Definições → Montra. Abrir a loja ao público e os contactos que ela mostra
 * (rodapé, página de contacto, botão "Fala connosco"). Um só formulário: são
 * poucos campos e mudam juntos quando se prepara o lançamento.
 */
export default function StoreSettingsPage({ store, networks }: Props) {
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

    const changeSocial = (network: string, value: string) => {
        setSaved(false);
        setData((current) => ({
            ...current,
            social: { ...current.social, [network]: value },
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
