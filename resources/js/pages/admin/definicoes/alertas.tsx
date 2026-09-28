import { Head, router, useForm } from '@inertiajs/react';
import { BellRing, ChevronDown, Send, ShieldAlert } from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/admin/confirm-dialog';
import { PageHeader } from '@/components/admin/page-header';
import { Panel } from '@/components/admin/panel';
import { StatusBadge } from '@/components/admin/status-badge';
import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { destroy, index, test, update } from '@/routes/admin/alertas';
import { index as settingsIndex } from '@/routes/admin/definicoes';

type Source = 'backoffice' | 'env' | 'off';

type Channel = {
    key: string;
    name: string;
    description: string;
    source: Source;
    /** Os últimos caracteres do URL — o URL inteiro nunca chega aqui. */
    hint: string | null;
    envName: string;
};

type Props = {
    channels: Channel[];
    jenkinsCredential: string;
};

const SOURCE_BADGES: Record<Source, { value: string; label: string }> = {
    backoffice: { value: 'alert_on', label: 'Ligado' },
    env: { value: 'alert_env', label: 'Ligado pelo .env' },
    off: { value: 'alert_off', label: 'Desligado' },
};

/**
 * Os webhooks do Discord, um por canal, e o guia de como os obter.
 *
 * O guia abre sozinho enquanto não houver canal nenhum ligado — é aí que faz
 * falta; depois fica fechado, à mão para quando for preciso outro.
 */
export default function AlertSettings({ channels, jenkinsCredential }: Props) {
    const noneConnected = channels.every((channel) => channel.source === 'off');

    return (
        <>
            <Head title="Alertas" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Alertas"
                    description="O 12studio avisa no Discord de tudo o que acontece — e do que devia acontecer e não aconteceu. Um canal por tipo de alerta."
                />

                <Guide
                    defaultOpen={noneConnected}
                    jenkinsCredential={jenkinsCredential}
                />

                <div className="grid max-w-3xl gap-4">
                    {channels.map((channel) => (
                        <ChannelCard key={channel.key} channel={channel} />
                    ))}
                </div>
            </div>
        </>
    );
}

function ChannelCard({ channel }: { channel: Channel }) {
    const [removing, setRemoving] = useState(false);
    const [testing, setTesting] = useState(false);
    const { data, setData, put, processing, errors, reset } = useForm({
        url: '',
    });
    const badge = SOURCE_BADGES[channel.source];

    const save = (event: React.FormEvent) => {
        event.preventDefault();
        put(update(channel.key).url, {
            preserveScroll: true,
            onSuccess: () => reset('url'),
        });
    };

    const sendTest = () => {
        setTesting(true);
        router.post(
            test(channel.key).url,
            {},
            { preserveScroll: true, onFinish: () => setTesting(false) },
        );
    };

    return (
        <Panel
            title={channel.name}
            description={channel.description}
            aside={<StatusBadge value={badge.value} label={badge.label} />}
        >
            {channel.source !== 'off' && (
                <p className="mb-3 text-xs text-muted-foreground">
                    {channel.source === 'env' ? (
                        <>
                            Vem do <code>{channel.envName}</code> no{' '}
                            <code>.env</code> do servidor. Um webhook guardado
                            aqui passa à frente dele.
                        </>
                    ) : (
                        <>
                            Webhook guardado, a terminar em{' '}
                            <code className="font-mono">{channel.hint}</code>.
                            Para trocar, cola o novo por cima.
                        </>
                    )}
                </p>
            )}

            <form onSubmit={save} className="flex flex-col gap-2">
                <Label htmlFor={`webhook-${channel.key}`}>
                    {channel.source === 'backoffice'
                        ? 'Novo URL do webhook'
                        : 'URL do webhook'}
                </Label>
                <div className="flex flex-col gap-2 sm:flex-row">
                    <Input
                        id={`webhook-${channel.key}`}
                        type="password"
                        autoComplete="off"
                        spellCheck={false}
                        value={data.url}
                        placeholder="https://discord.com/api/webhooks/…"
                        onChange={(event) => setData('url', event.target.value)}
                        required
                    />
                    <Button type="submit" disabled={processing}>
                        {processing && <Spinner />}
                        Guardar
                    </Button>
                </div>
                <InputError message={errors.url} />
            </form>

            {channel.source !== 'off' && (
                <div className="mt-3 flex flex-wrap gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={sendTest}
                        disabled={testing}
                    >
                        {testing ? <Spinner /> : <Send />}
                        Testar
                    </Button>
                    {channel.source === 'backoffice' && (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => setRemoving(true)}
                        >
                            Remover
                        </Button>
                    )}
                </div>
            )}

            <ConfirmDialog
                open={removing}
                onOpenChange={setRemoving}
                title={`Remover o webhook de ${channel.name}`}
                description="O canal deixa de receber alertas (ou volta ao webhook do .env, se houver um). O webhook continua a existir no Discord — apaga-o lá se já não o quiseres."
                confirmLabel="Remover"
                destructive
                onConfirm={() =>
                    router.delete(destroy(channel.key).url, {
                        preserveScroll: true,
                        onFinish: () => setRemoving(false),
                    })
                }
            />
        </Panel>
    );
}

function Guide({
    defaultOpen,
    jenkinsCredential,
}: {
    defaultOpen: boolean;
    jenkinsCredential: string;
}) {
    return (
        <Collapsible
            defaultOpen={defaultOpen}
            className="max-w-3xl rounded-xl border border-border/60 bg-card"
        >
            <CollapsibleTrigger className="group flex w-full items-center justify-between gap-3 p-4 text-left">
                <span className="flex items-center gap-2 text-sm font-semibold">
                    <BellRing className="size-4" />
                    Como obter um webhook do Discord
                </span>
                <ChevronDown className="size-4 text-muted-foreground transition-transform group-data-[state=open]:rotate-180" />
            </CollapsibleTrigger>
            <CollapsibleContent className="flex flex-col gap-5 px-4 pb-4 text-sm">
                <section>
                    <h3 className="mb-2 font-medium">
                        1. Cria os canais no teu servidor do Discord
                    </h3>
                    <p className="text-muted-foreground">
                        Um por tipo de alerta: <strong>#encomendas</strong>,{' '}
                        <strong>#stock-producao</strong>,{' '}
                        <strong>#seguranca</strong> e <strong>#sistema</strong>.
                        No Discord, carrega no <strong>+</strong> ao lado de
                        “Canais de texto”. Se quiseres que só tu os vejas, liga
                        “Canal privado”.
                    </p>
                </section>

                <section>
                    <h3 className="mb-2 font-medium">
                        2. Cria um webhook em cada canal
                    </h3>
                    <ol className="list-decimal space-y-1 pl-5 text-muted-foreground">
                        <li>
                            Passa o rato por cima do canal e carrega na{' '}
                            <strong>roda dentada</strong> (“Editar canal”). No
                            telemóvel: carrega sem largar no canal →{' '}
                            <strong>Editar canal</strong>.
                        </li>
                        <li>
                            Vai a <strong>Integrações</strong> →{' '}
                            <strong>Webhooks</strong> →{' '}
                            <strong>Novo webhook</strong>.
                        </li>
                        <li>
                            Dá-lhe um nome (por exemplo “12studio”) — é o nome
                            que aparece nas mensagens.
                        </li>
                        <li>
                            Carrega em <strong>Copiar URL do webhook</strong>.
                        </li>
                        <li>
                            Cola-o aqui em baixo, no cartão do canal certo,
                            carrega em <strong>Guardar</strong> e depois em{' '}
                            <strong>Testar</strong>. Deve aparecer uma mensagem
                            no canal em segundos.
                        </li>
                    </ol>
                    <p className="mt-2 text-xs text-muted-foreground">
                        Se não vês “Integrações”, a tua conta não tem a
                        permissão <strong>Gerir webhooks</strong> nesse servidor
                        — pede-a a quem o administra.
                    </p>
                </section>

                <section>
                    <h3 className="mb-2 font-medium">
                        3. Escolhe o que te acorda
                    </h3>
                    <p className="text-muted-foreground">
                        No Discord, carrega no nome de cada canal →{' '}
                        <strong>Definições de notificação</strong>. Sugestão:{' '}
                        <strong>#seguranca</strong> e <strong>#sistema</strong>{' '}
                        com “Todas as mensagens”; os outros em silêncio, para
                        ler quando quiseres.
                    </p>
                </section>

                <Alert>
                    <ShieldAlert />
                    <AlertTitle>O URL do webhook é uma password</AlertTitle>
                    <AlertDescription>
                        Quem o tiver consegue escrever no canal. Não o partilhes
                        nem o ponhas em mensagens. Se achares que fugiu, apaga-o
                        no Discord (Editar canal → Integrações → Webhooks) e
                        cria outro. Aqui só se guardam URLs de webhooks do
                        Discord, e cada mudança fica avisada no #seguranca.
                    </AlertDescription>
                </Alert>

                <section>
                    <h3 className="mb-2 font-medium">
                        Avisos de deploy (opcional)
                    </h3>
                    <p className="text-muted-foreground">
                        O “deploy começou / OK / falhou” vem do Jenkins, que não
                        lê o backoffice. Para o ligar: no Jenkins,{' '}
                        <strong>Manage Jenkins</strong> →{' '}
                        <strong>Credentials</strong> → <strong>(global)</strong>{' '}
                        → <strong>Add Credentials</strong>, tipo{' '}
                        <strong>Secret text</strong>, com o URL do webhook do{' '}
                        <strong>#sistema</strong> como segredo e o ID{' '}
                        <code className="font-mono">{jenkinsCredential}</code>.
                        Sem ela, os deploys funcionam na mesma — só não avisam.
                    </p>
                </section>
            </CollapsibleContent>
        </Collapsible>
    );
}

AlertSettings.layout = {
    breadcrumbs: [
        { title: 'Backoffice', href: '/admin' },
        { title: 'Definições', href: settingsIndex() },
        { title: 'Alertas', href: index() },
    ],
};
