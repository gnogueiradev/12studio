import { Head } from '@inertiajs/react';
import { ShieldAlert } from 'lucide-react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';

type Props = {
    clientName: string;
    /** O domínio para onde o acesso vai ser entregue — o que conta mesmo. */
    redirectHost: string;
    scopes: { id: string; description: string }[];
    canWrite: boolean;
    authToken: string;
    csrfToken: string;
    approveUrl: string;
    denyUrl: string;
    accountName: string;
};

/**
 * Ecrã de consentimento OAuth: o Claude (claude.ai, app, telemóvel) pede
 * acesso ao backoffice.
 *
 * Formulários HTML normais, e não pedidos Inertia: a resposta é um redirect
 * para o domínio da aplicação, e o Inertia não segue redirects para fora do
 * site.
 *
 * O nome da aplicação é escolhido por quem a registou — pode dizer "Claude"
 * sem o ser. Por isso o destaque vai para o domínio, que o servidor só aceita
 * se for claude.ai ou claude.com.
 */
export default function OAuthAuthorize({
    clientName,
    redirectHost,
    scopes,
    canWrite,
    authToken,
    csrfToken,
    approveUrl,
    denyUrl,
    accountName,
}: Props) {
    return (
        <>
            <Head title="Autorizar aplicação" />

            <div className="flex flex-col gap-6">
                <div className="rounded-lg border p-4 text-center">
                    <p className="text-xs text-muted-foreground uppercase">
                        O acesso vai ser entregue a
                    </p>
                    <p className="mt-1 text-xl font-semibold break-all">
                        {redirectHost}
                    </p>
                    <p className="mt-1 text-sm text-muted-foreground">
                        que se apresenta como “{clientName}”
                    </p>
                </div>

                <div className="flex flex-col gap-2 text-sm">
                    <p>
                        Com a conta <strong>{accountName}</strong>, esta
                        aplicação vai poder:
                    </p>
                    <ul className="list-disc space-y-1 pl-5">
                        {scopes.map((scope) => (
                            <li key={scope.id}>{scope.description}</li>
                        ))}
                    </ul>
                    <p className="text-muted-foreground">
                        O acesso dura 1 hora e renova-se sozinho até 30 dias.
                        Podes revogá-lo quando quiseres em Definições → Chaves
                        de API.
                    </p>
                </div>

                {canWrite && (
                    <Alert>
                        <ShieldAlert />
                        <AlertTitle>Inclui alterar dados</AlertTitle>
                        <AlertDescription>
                            No Claude, não dês “permitir sempre” às ferramentas
                            que alteram preços, stock ou encomendas — assim cada
                            alteração passa por ti.
                        </AlertDescription>
                    </Alert>
                )}

                <div className="flex gap-3">
                    <form method="post" action={denyUrl} className="flex-1">
                        <input type="hidden" name="_token" value={csrfToken} />
                        <input type="hidden" name="_method" value="DELETE" />
                        <input
                            type="hidden"
                            name="auth_token"
                            value={authToken}
                        />
                        <Button
                            type="submit"
                            variant="outline"
                            className="w-full"
                        >
                            Recusar
                        </Button>
                    </form>

                    <form method="post" action={approveUrl} className="flex-1">
                        <input type="hidden" name="_token" value={csrfToken} />
                        <input
                            type="hidden"
                            name="auth_token"
                            value={authToken}
                        />
                        <Button type="submit" className="w-full">
                            Autorizar
                        </Button>
                    </form>
                </div>
            </div>
        </>
    );
}

OAuthAuthorize.layout = {
    title: 'Ligar uma aplicação ao 12studio',
    description: 'Confirma que foste tu a pedir este acesso.',
};
