import { Head, router } from '@inertiajs/react';
import { ChevronDown, PlugZap, Send, ShieldAlert, Store } from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/admin/confirm-dialog';
import { PageHeader } from '@/components/admin/page-header';
import { Panel } from '@/components/admin/panel';
import { StatusBadge } from '@/components/admin/status-badge';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Spinner } from '@/components/ui/spinner';
import { index as settingsIndex } from '@/routes/admin/definicoes';
import { desligar, index, ligar, testar } from '@/routes/admin/etsy';

type Connection = {
    shopId: number;
    shopName: string;
    etsyUserId: number;
    /** Permissões que a loja deu à app. */
    scopes: string[];
    /** As que a integração precisa e a loja ainda não deu. */
    missingScopes: string[];
    connectedAt: string | null;
    /** A partir de quando as encomendas da Etsy são importadas. */
    ordersSince: string | null;
    /** O token de renovação deixou de servir: só religando. */
    broken: boolean;
    lastError: string | null;
};

type Props = {
    /** `ETSY_ENABLED` no servidor. Desligada, nada sai nem entra. */
    enabled: boolean;
    /** Se a chave e o segredo da app estão no `.env`. */
    configured: boolean;
    /** O endereço a registar na app da Etsy, letra por letra. */
    redirectUri: string;
    requiredScopes: string[];
    connection: Connection | null;
};

function badge(props: Props): { value: string; label: string } {
    if (props.connection === null) {
        return { value: 'etsy_off', label: 'Sem loja ligada' };
    }

    if (props.connection.broken) {
        return { value: 'etsy_broken', label: 'Ligação partida' };
    }

    if (!props.enabled) {
        return { value: 'etsy_paused', label: 'Ligada, em pausa' };
    }

    return { value: 'etsy_connected', label: 'Ligada' };
}

/**
 * Definições → Etsy: ligar a loja, ver em que estado está a ligação, desligar.
 *
 * O guia abre sozinho enquanto faltar a chave da app — é aí que faz falta.
 */
export default function EtsySettings(props: Props) {
    const { enabled, configured, redirectUri, requiredScopes, connection } =
        props;
    const [disconnecting, setDisconnecting] = useState(false);
    const [testing, setTesting] = useState(false);
    const state = badge(props);

    const sendTest = () => {
        setTesting(true);
        router.post(
            testar().url,
            {},
            { preserveScroll: true, onFinish: () => setTesting(false) },
        );
    };

    return (
        <>
            <Head title="Etsy" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Etsy"
                    description="A loja da Etsy ligada ao 12studio: os produtos daqui tornam-se anúncios lá, e as encomendas de lá entram aqui."
                />

                <Guide
                    defaultOpen={!configured}
                    redirectUri={redirectUri}
                    requiredScopes={requiredScopes}
                />

                <div className="grid max-w-3xl gap-4">
                    {!configured && (
                        <Alert>
                            <ShieldAlert />
                            <AlertTitle>
                                Falta a chave da app da Etsy
                            </AlertTitle>
                            <AlertDescription>
                                Enquanto o <code>ETSY_KEYSTRING</code> e o{' '}
                                <code>ETSY_SHARED_SECRET</code> não estiverem no{' '}
                                <code>.env</code> do servidor, não há como ligar
                                a loja. O guia acima diz onde os ir buscar.
                            </AlertDescription>
                        </Alert>
                    )}

                    {configured && !enabled && (
                        <Alert>
                            <ShieldAlert />
                            <AlertTitle>A integração está desligada</AlertTitle>
                            <AlertDescription>
                                Com <code>ETSY_ENABLED=false</code> no servidor
                                nada é enviado para a Etsy nem importado de lá —
                                nem o pedido para ligar a loja. Passa a
                                funcionar quando o interruptor for{' '}
                                <code>true</code>.
                            </AlertDescription>
                        </Alert>
                    )}

                    <Panel
                        title="Ligação"
                        description="Uma loja de cada vez. Ligar pede-te autorização na Etsy; aqui só fica guardado o que é preciso para falar com ela, cifrado."
                        aside={
                            <StatusBadge
                                value={state.value}
                                label={state.label}
                            />
                        }
                    >
                        {connection === null ? (
                            <div className="flex flex-col items-start gap-3">
                                <p className="text-sm text-muted-foreground">
                                    Ainda não há loja ligada.
                                </p>
                                <ConnectButton
                                    disabled={!configured || !enabled}
                                >
                                    Ligar à Etsy
                                </ConnectButton>
                            </div>
                        ) : (
                            <div className="flex flex-col gap-4">
                                <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-[auto_1fr]">
                                    <dt className="text-muted-foreground">
                                        Loja
                                    </dt>
                                    <dd className="flex items-center gap-2 font-medium">
                                        <Store className="size-4 text-muted-foreground" />
                                        {connection.shopName}
                                        <span className="font-normal text-muted-foreground tabular-nums">
                                            #{connection.shopId}
                                        </span>
                                    </dd>

                                    <dt className="text-muted-foreground">
                                        Ligada em
                                    </dt>
                                    <dd>{connection.connectedAt ?? '—'}</dd>

                                    <dt className="text-muted-foreground">
                                        Encomendas desde
                                    </dt>
                                    <dd>{connection.ordersSince ?? '—'}</dd>

                                    <dt className="text-muted-foreground">
                                        Permissões
                                    </dt>
                                    <dd className="font-mono text-xs leading-relaxed text-muted-foreground">
                                        {connection.scopes.join(' ')}
                                    </dd>
                                </dl>

                                {connection.broken && (
                                    <Alert variant="destructive">
                                        <ShieldAlert />
                                        <AlertTitle>
                                            A Etsy deixou de aceitar esta
                                            ligação
                                        </AlertTitle>
                                        <AlertDescription>
                                            Acontece quando a autorização é
                                            retirada na Etsy ou passa muito
                                            tempo sem uso. Volta a ligar: as
                                            encomendas que entraram entretanto
                                            são importadas a seguir.
                                        </AlertDescription>
                                    </Alert>
                                )}

                                {connection.missingScopes.length > 0 && (
                                    <Alert>
                                        <ShieldAlert />
                                        <AlertTitle>
                                            Faltam permissões
                                        </AlertTitle>
                                        <AlertDescription>
                                            A loja foi ligada sem{' '}
                                            <code className="font-mono">
                                                {connection.missingScopes.join(
                                                    ' ',
                                                )}
                                            </code>
                                            . Volta a ligar para as dar — sem
                                            elas, parte das funções vai ser
                                            recusada pela Etsy.
                                        </AlertDescription>
                                    </Alert>
                                )}

                                <div className="flex flex-wrap gap-2">
                                    {(connection.broken ||
                                        connection.missingScopes.length >
                                            0) && (
                                        <ConnectButton
                                            disabled={!configured || !enabled}
                                        >
                                            Voltar a ligar
                                        </ConnectButton>
                                    )}
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={sendTest}
                                        disabled={testing || !enabled}
                                    >
                                        {testing ? <Spinner /> : <Send />}
                                        Testar
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => setDisconnecting(true)}
                                    >
                                        Desligar
                                    </Button>
                                </div>
                            </div>
                        )}
                    </Panel>
                </div>

                <ConfirmDialog
                    open={disconnecting}
                    onOpenChange={setDisconnecting}
                    title="Desligar a loja da Etsy"
                    description="O 12studio deixa de enviar anúncios e de importar encomendas. Os anúncios que já estão na Etsy ficam como estão, e o que foi importado fica cá. Para tirar o acesso do lado da Etsy, remove a app em Conta → Apps."
                    confirmLabel="Desligar"
                    destructive
                    onConfirm={() =>
                        router.delete(desligar().url, {
                            preserveScroll: true,
                            onFinish: () => setDisconnecting(false),
                        })
                    }
                />
            </div>
        </>
    );
}

/**
 * Uma ligação a sério e não um `<Link>`: a resposta é um redirect para fora
 * (etsy.com), e o Inertia tentava segui-lo por XHR.
 */
function ConnectButton({
    disabled,
    children,
}: {
    disabled: boolean;
    children: React.ReactNode;
}) {
    if (disabled) {
        return (
            <Button size="sm" disabled>
                <PlugZap />
                {children}
            </Button>
        );
    }

    return (
        <Button size="sm" asChild>
            <a href={ligar().url}>
                <PlugZap />
                {children}
            </a>
        </Button>
    );
}

function Guide({
    defaultOpen,
    redirectUri,
    requiredScopes,
}: {
    defaultOpen: boolean;
    redirectUri: string;
    requiredScopes: string[];
}) {
    return (
        <Collapsible
            defaultOpen={defaultOpen}
            className="max-w-3xl rounded-xl border border-border/60 bg-card"
        >
            <CollapsibleTrigger className="group flex w-full items-center justify-between gap-3 p-4 text-left">
                <span className="flex items-center gap-2 text-sm font-semibold">
                    <PlugZap className="size-4" />
                    Como criar a app na Etsy
                </span>
                <ChevronDown className="size-4 text-muted-foreground transition-transform group-data-[state=open]:rotate-180" />
            </CollapsibleTrigger>
            <CollapsibleContent className="flex flex-col gap-5 px-4 pb-4 text-sm">
                <section>
                    <h3 className="mb-2 font-medium">
                        1. Cria a app com a conta dona da loja
                    </h3>
                    <p className="text-muted-foreground">
                        Em <strong>etsy.com/developers</strong> →{' '}
                        <strong>Create a New App</strong>, com a sessão da conta
                        que é dona da loja. Feita por outra conta, a Etsy trata
                        a app como sendo de terceiros e pode recusar-lhe marcar
                        envios.
                    </p>
                </section>

                <section>
                    <h3 className="mb-2 font-medium">
                        2. Regista este endereço de retorno
                    </h3>
                    <p className="text-muted-foreground">
                        Nas definições da app, em <strong>Callback URLs</strong>
                        , tem de estar exatamente isto — a Etsy compara letra a
                        letra:
                    </p>
                    <code className="mt-2 block rounded-lg bg-secondary/50 px-3 py-2 font-mono text-xs break-all">
                        {redirectUri}
                    </code>
                </section>

                <section>
                    <h3 className="mb-2 font-medium">
                        3. Põe a chave e o segredo no servidor
                    </h3>
                    <p className="text-muted-foreground">
                        A app mostra um <strong>keystring</strong> e um{' '}
                        <strong>shared secret</strong>. Vão para o{' '}
                        <code>.env</code> do servidor, como{' '}
                        <code>ETSY_KEYSTRING</code> e{' '}
                        <code>ETSY_SHARED_SECRET</code>, com{' '}
                        <code>ETSY_ENABLED=true</code>. Não passam pelo
                        backoffice de propósito: são a identidade da app, não
                        uma preferência.
                    </p>
                </section>

                <section>
                    <h3 className="mb-2 font-medium">4. Liga a loja</h3>
                    <p className="text-muted-foreground">
                        Carrega em <strong>Ligar à Etsy</strong>, aqui em baixo.
                        A Etsy mostra-te o que a app pede e tu autorizas. São
                        estas as permissões:
                    </p>
                    <code className="mt-2 block rounded-lg bg-secondary/50 px-3 py-2 font-mono text-xs leading-relaxed">
                        {requiredScopes.join(' ')}
                    </code>
                </section>
            </CollapsibleContent>
        </Collapsible>
    );
}

EtsySettings.layout = {
    breadcrumbs: [
        { title: 'Backoffice', href: '/admin' },
        { title: 'Definições', href: settingsIndex() },
        { title: 'Etsy', href: index() },
    ],
};
