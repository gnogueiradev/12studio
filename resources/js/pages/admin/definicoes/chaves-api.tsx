import { Head, router, useForm } from '@inertiajs/react';
import { Check, Copy, KeyRound, ShieldAlert } from 'lucide-react';
import { useState } from 'react';
import { AdminTable } from '@/components/admin/admin-table';
import type { Column } from '@/components/admin/admin-table';
import { ConfirmDialog } from '@/components/admin/confirm-dialog';
import { PageHeader } from '@/components/admin/page-header';
import { StatusBadge } from '@/components/admin/status-badge';
import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
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
import { destroy, destroyAll, index, store } from '@/routes/admin/chaves-api';
import { index as settingsIndex } from '@/routes/admin/definicoes';

type ApiKeyRow = {
    id: string;
    name: string;
    kind: 'key' | 'oauth';
    access: 'read' | 'write';
    createdAt: string | null;
    expiresAt: string | null;
    lastUsedAt: string | null;
};

type Props = {
    keys: ApiKeyRow[];
    lifetimes: number[];
    noExpiry: string;
    mcpUrl: string;
    enabled: boolean;
    createdToken: string | null;
};

const ACCESS_LABELS = {
    read: 'Só leitura',
    write: 'Leitura e escrita',
} as const;

/**
 * Chaves para ligar o Claude ao backoffice pelo MCP.
 *
 * O claude.ai, o Desktop e o telemóvel ligam-se por OAuth só com o URL
 * (ClaudeAiConnector); as chaves são para o Claude Code, que manda o header.
 *
 * A chave criada aparece uma única vez, já dentro do comando `claude mcp add`
 * pronto a colar. Depois disso o servidor só guarda o id — quem a perder cria
 * outra e revoga a antiga.
 */
export default function ApiKeysIndex({
    keys,
    lifetimes,
    noExpiry,
    mcpUrl,
    enabled,
    createdToken,
}: Props) {
    const [revoking, setRevoking] = useState<ApiKeyRow | null>(null);
    const [revokingAll, setRevokingAll] = useState(false);

    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        access: 'read',
        days: String(lifetimes[1] ?? lifetimes[0]),
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        post(store().url, { onSuccess: () => reset('name') });
    };

    const columns: Column<ApiKeyRow>[] = [
        {
            key: 'name',
            header: 'Nome',
            cell: (row) => (
                <div>
                    <span className="font-medium">{row.name}</span>
                    <span className="mt-0.5 block text-xs text-muted-foreground">
                        {row.kind === 'key'
                            ? 'Chave de API'
                            : 'Aplicação ligada (OAuth)'}
                    </span>
                </div>
            ),
        },
        {
            key: 'access',
            header: 'Acesso',
            cell: (row) => (
                <StatusBadge
                    value={row.access === 'write' ? 'in_production' : 'active'}
                    label={ACCESS_LABELS[row.access]}
                />
            ),
        },
        {
            key: 'lastUsed',
            header: 'Último uso',
            className: 'text-xs text-muted-foreground tabular-nums',
            cell: (row) => row.lastUsedAt ?? 'Nunca',
        },
        {
            key: 'expires',
            header: 'Expira',
            className: 'text-xs text-muted-foreground tabular-nums',
            cell: (row) => row.expiresAt ?? 'Nunca',
        },
        {
            key: 'actions',
            header: '',
            className: 'text-right',
            cell: (row) => (
                <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => setRevoking(row)}
                >
                    Revogar
                </Button>
            ),
        },
    ];

    return (
        <>
            <Head title="Chaves de API" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Chaves de API"
                    description="Ligam o Claude ao backoffice pelo MCP: ver e criar produtos, mexer em preços e stock, registar encomendas."
                >
                    {keys.length > 0 && (
                        <Button
                            variant="outline"
                            onClick={() => setRevokingAll(true)}
                        >
                            Revogar tudo
                        </Button>
                    )}
                </PageHeader>

                {!enabled && (
                    <Alert>
                        <ShieldAlert />
                        <AlertTitle>O MCP está desligado</AlertTitle>
                        <AlertDescription>
                            O servidor responde 503 a todos os pedidos até
                            voltar a ser ligado (MCP_ENABLED).
                        </AlertDescription>
                    </Alert>
                )}

                {createdToken && (
                    <CreatedToken token={createdToken} mcpUrl={mcpUrl} />
                )}

                <ClaudeAiConnector mcpUrl={mcpUrl} />

                <h2 className="text-sm font-medium">
                    Chave para o Claude Code
                </h2>

                <form
                    onSubmit={submit}
                    className="grid gap-4 rounded-xl border p-4 sm:grid-cols-[1fr_12rem_10rem_auto] sm:items-end"
                >
                    <div className="grid gap-2">
                        <Label htmlFor="key-name">Nome</Label>
                        <Input
                            id="key-name"
                            value={data.name}
                            placeholder="Claude Code no portátil"
                            onChange={(event) =>
                                setData('name', event.target.value)
                            }
                            required
                        />
                        <InputError message={errors.name} />
                    </div>
                    <div className="grid gap-2">
                        <Label>Acesso</Label>
                        <Select
                            value={data.access}
                            onValueChange={(value) => setData('access', value)}
                        >
                            <SelectTrigger>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="read">
                                    {ACCESS_LABELS.read}
                                </SelectItem>
                                <SelectItem value="write">
                                    {ACCESS_LABELS.write}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError message={errors.access} />
                    </div>
                    <div className="grid gap-2">
                        <Label>Validade</Label>
                        <Select
                            value={data.days}
                            onValueChange={(value) => setData('days', value)}
                        >
                            <SelectTrigger>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {lifetimes.map((days) => (
                                    <SelectItem key={days} value={String(days)}>
                                        {days} dias
                                    </SelectItem>
                                ))}
                                <SelectItem value={noExpiry}>
                                    Sem validade
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError message={errors.days} />
                    </div>
                    <Button type="submit" disabled={processing}>
                        {processing ? <Spinner /> : <KeyRound />}
                        Criar chave
                    </Button>
                    <p className="text-xs text-muted-foreground sm:col-span-4">
                        Usa uma chave <strong>só de leitura</strong> no dia a
                        dia e cria uma de escrita só quando precisares. No
                        Claude, não dês “permitir sempre” às ferramentas que
                        alteram dados — assim cada alteração passa por ti.
                    </p>
                    {data.days === noExpiry && (
                        <p className="text-xs text-warning sm:col-span-4">
                            Uma chave sem validade só deixa de funcionar quando
                            for revogada. Guarda-a como uma password.
                        </p>
                    )}
                </form>

                <AdminTable
                    columns={columns}
                    rows={keys}
                    rowKey={(row) => row.id}
                    empty="Ainda não há chaves nem aplicações ligadas."
                />
            </div>

            <ConfirmDialog
                open={revoking !== null}
                onOpenChange={(open) => !open && setRevoking(null)}
                title="Revogar chave"
                description={
                    <>
                        <strong>{revoking?.name}</strong> deixa de funcionar já.
                        Quem a estiver a usar tem de receber uma nova.
                    </>
                }
                confirmLabel="Revogar"
                destructive
                onConfirm={() => {
                    if (revoking) {
                        router.delete(destroy(revoking.id).url, {
                            preserveScroll: true,
                            onFinish: () => setRevoking(null),
                        });
                    }
                }}
            />

            <ConfirmDialog
                open={revokingAll}
                onOpenChange={setRevokingAll}
                title="Revogar todas as chaves"
                description="Todas as chaves e aplicações ligadas à tua conta deixam de funcionar já. Usa isto se achares que alguma foi exposta."
                confirmLabel="Revogar tudo"
                destructive
                onConfirm={() =>
                    router.delete(destroyAll().url, {
                        preserveScroll: true,
                        onFinish: () => setRevokingAll(false),
                    })
                }
            />
        </>
    );
}

function CreatedToken({ token, mcpUrl }: { token: string; mcpUrl: string }) {
    const [copied, setCopied] = useState(false);
    const command = `claude mcp add --transport http 12studio ${mcpUrl} --header "Authorization: Bearer ${token}"`;

    const copy = async () => {
        await navigator.clipboard.writeText(command);
        setCopied(true);
    };

    return (
        <Alert>
            <KeyRound />
            <AlertTitle>Chave criada — copia-a agora</AlertTitle>
            <AlertDescription className="flex flex-col gap-2">
                <span>
                    Não volta a aparecer. Cola este comando no terminal para a
                    ligar ao Claude Code:
                </span>
                <code className="block max-h-32 overflow-auto rounded-md bg-muted p-2 text-xs break-all">
                    {command}
                </code>
                <Button
                    variant="outline"
                    size="sm"
                    className="self-start"
                    onClick={copy}
                >
                    {copied ? <Check /> : <Copy />}
                    {copied ? 'Copiado' : 'Copiar comando'}
                </Button>
            </AlertDescription>
        </Alert>
    );
}

/**
 * O claude.ai, o Desktop e o telemóvel não aceitam headers: ligam-se por
 * OAuth (routes/ai.php). Basta-lhes o URL — o registo do cliente é dinâmico
 * e o consentimento pede login de admin e a password. O /login está atrás do
 * EnsureLoginGate, por isso o passo 1 manda fazer isto com sessão aberta.
 * A ligação aparece
 * depois na tabela como "Aplicação ligada (OAuth)".
 */
function ClaudeAiConnector({ mcpUrl }: { mcpUrl: string }) {
    const [copied, setCopied] = useState(false);

    const copy = async () => {
        await navigator.clipboard.writeText(mcpUrl);
        setCopied(true);
    };

    return (
        <section className="flex flex-col gap-3 rounded-xl border p-4">
            <div>
                <h2 className="text-sm font-medium">
                    Ligar ao claude.ai, ao Desktop e ao telemóvel
                </h2>
                <p className="mt-1 text-sm text-muted-foreground">
                    Não precisa de chave: o Claude pede-te login aqui e tu
                    autorizas. Liga-se uma vez e fica em todo o lado.
                </p>
            </div>
            <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                <code className="flex-1 rounded-md bg-muted p-2 text-xs break-all">
                    {mcpUrl}
                </code>
                <Button
                    variant="outline"
                    size="sm"
                    className="self-start sm:self-auto"
                    onClick={copy}
                >
                    {copied ? <Check /> : <Copy />}
                    {copied ? 'Copiado' : 'Copiar URL'}
                </Button>
            </div>
            <ol className="list-decimal space-y-1 pl-5 text-sm text-muted-foreground">
                <li>
                    Faz isto no computador, no browser onde já tens sessão no
                    backoffice — o login está escondido atrás do link secreto e
                    daria 404. O conector passa sozinho para o Desktop e para o
                    telemóvel.
                </li>
                <li>
                    No claude.ai, abre <strong>Settings → Connectors</strong> e
                    escolhe <strong>Add custom connector</strong>.
                </li>
                <li>
                    Dá-lhe o nome <strong>12studio</strong> e cola o URL acima.
                    Deixa o Client ID e o Client Secret vazios.
                </li>
                <li>
                    Carrega em <strong>Connect</strong>, entra com a tua conta
                    de admin e autoriza. Confirma que o destino é claude.ai.
                </li>
                <li>
                    No Claude, não dês “permitir sempre” às ferramentas que
                    alteram dados — assim cada alteração passa por ti.
                </li>
            </ol>
        </section>
    );
}

ApiKeysIndex.layout = {
    breadcrumbs: [
        { title: 'Backoffice', href: '/admin' },
        { title: 'Definições', href: settingsIndex() },
        { title: 'Chaves de API', href: index() },
    ],
};
