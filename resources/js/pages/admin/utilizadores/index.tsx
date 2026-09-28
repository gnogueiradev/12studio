import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { AdminTable } from '@/components/admin/admin-table';
import type { Column } from '@/components/admin/admin-table';
import { ConfirmDialog } from '@/components/admin/confirm-dialog';
import { PageHeader } from '@/components/admin/page-header';
import {
    StaffDialog,
    StaffPasswordDialog,
} from '@/components/admin/staff-dialog';
import { StatusBadge } from '@/components/admin/status-badge';
import { Button } from '@/components/ui/button';
import { desativar, index, reativar } from '@/routes/admin/utilizadores';
import type { StaffRow } from '@/types/staff';
import { ROLE_LABELS } from '@/types/staff';

type Props = {
    staff: StaffRow[];
    passwordRules: string;
};

/**
 * A equipa: quem entra no backoffice. Só o dono vê esta página, e cada ação
 * pede a password dele outra vez (middleware `password.confirm`).
 *
 * Não há "apagar": uma conta com historial (encomendas criadas, movimentos de
 * stock) desativa-se, e o historial continua a dizer quem fez o quê.
 */
export default function StaffIndex({ staff, passwordRules }: Props) {
    const [creating, setCreating] = useState(false);
    const [editing, setEditing] = useState<StaffRow | null>(null);
    const [resetting, setResetting] = useState<StaffRow | null>(null);
    const [disabling, setDisabling] = useState<StaffRow | null>(null);

    const columns: Column<StaffRow>[] = [
        {
            key: 'name',
            header: 'Pessoa',
            cell: (row) => (
                <div>
                    <span className="font-medium">{row.name}</span>
                    {row.isSelf && (
                        <span className="ml-2 text-xs text-muted-foreground">
                            (tu)
                        </span>
                    )}
                    <span className="mt-0.5 block text-xs text-muted-foreground">
                        {row.email}
                    </span>
                </div>
            ),
        },
        {
            key: 'role',
            header: 'Papel',
            cell: (row) => (
                <StatusBadge value={row.role} label={ROLE_LABELS[row.role]} />
            ),
        },
        {
            key: 'security',
            header: 'Segurança',
            className: 'text-xs text-muted-foreground',
            cell: (row) => (
                <div className="flex flex-col gap-0.5">
                    <span>
                        {row.twoFactorEnabled ? '2FA ativo' : 'Sem 2FA'}
                    </span>
                    {row.mustChangePassword && (
                        <span className="text-warning">Password por mudar</span>
                    )}
                </div>
            ),
        },
        {
            key: 'created',
            header: 'Criada',
            className: 'text-xs text-muted-foreground',
            cell: (row) =>
                row.createdBy
                    ? `${row.createdAt} por ${row.createdBy}`
                    : (row.createdAt ?? '—'),
        },
        {
            key: 'state',
            header: 'Estado',
            cell: (row) => (
                <StatusBadge
                    value={row.disabled ? 'archived' : 'active'}
                    label={row.disabled ? 'Desativada' : 'Ativa'}
                />
            ),
        },
        {
            key: 'actions',
            header: '',
            className: 'text-right',
            cell: (row) => {
                // O dono e a própria conta só mudam nome e email.
                const locked = row.role === 'owner' || row.isSelf;

                return (
                    <div className="flex justify-end gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => setEditing(row)}
                        >
                            Editar
                        </Button>
                        {!locked && (
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => setResetting(row)}
                            >
                                Password
                            </Button>
                        )}
                        {!locked &&
                            (row.disabled ? (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={() =>
                                        router.patch(
                                            reativar(row.id).url,
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Reativar
                                </Button>
                            ) : (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => setDisabling(row)}
                                >
                                    Desativar
                                </Button>
                            ))}
                    </div>
                );
            },
        },
    ];

    return (
        <>
            <Head title="Equipa" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Equipa"
                    description="Quem entra no backoffice. Administradores veem tudo; a produção só vê o quadro de produção."
                >
                    <Button onClick={() => setCreating(true)}>
                        Nova conta
                    </Button>
                </PageHeader>

                <AdminTable
                    columns={columns}
                    rows={staff}
                    rowKey={(row) => row.id}
                    rowClassName={(row) => (row.disabled ? 'opacity-60' : '')}
                    empty="Ainda não há ninguém na equipa."
                />
            </div>

            {/* A `key` força o remonte: o useForm só lê os valores iniciais uma vez. */}
            <StaffDialog
                key={editing?.id ?? 'new'}
                open={creating || editing !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setCreating(false);
                        setEditing(null);
                    }
                }}
                editing={editing}
                passwordRules={passwordRules}
            />

            <StaffPasswordDialog
                key={`reset-${resetting?.id ?? 'none'}`}
                target={resetting}
                onOpenChange={(open) => !open && setResetting(null)}
                passwordRules={passwordRules}
            />

            <ConfirmDialog
                open={disabling !== null}
                onOpenChange={(open) => !open && setDisabling(null)}
                title="Desativar conta"
                description={
                    <>
                        <strong>{disabling?.name}</strong> sai da sessão no
                        próximo clique e deixa de conseguir entrar. O que já fez
                        continua registado em nome dela. Podes reativar a conta
                        quando quiseres.
                    </>
                }
                confirmLabel="Desativar"
                destructive
                onConfirm={() => {
                    if (disabling) {
                        router.patch(
                            desativar(disabling.id).url,
                            {},
                            {
                                preserveScroll: true,
                                onFinish: () => setDisabling(null),
                            },
                        );
                    }
                }}
            />
        </>
    );
}

StaffIndex.layout = {
    breadcrumbs: [
        { title: 'Backoffice', href: '/admin' },
        { title: 'Equipa', href: index() },
    ],
};
