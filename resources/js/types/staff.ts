export type StaffRole = 'owner' | 'admin' | 'production';

/** Uma conta da equipa tal como o StaffController a lista. */
export type StaffRow = {
    id: number;
    name: string;
    email: string;
    role: StaffRole;
    disabled: boolean;
    mustChangePassword: boolean;
    twoFactorEnabled: boolean;
    createdBy: string | null;
    createdAt: string | null;
    isSelf: boolean;
};

/**
 * Os papéis que o dono pode dar. O `owner` fica de fora: só o comando
 * `users:make-owner` o atribui.
 */
export const STAFF_ROLES = [
    {
        value: 'admin',
        label: 'Administrador',
        hint: 'Acesso total ao backoffice.',
    },
    {
        value: 'production',
        label: 'Produção',
        hint: 'Só o quadro de produção — sem preços, clientes nem definições.',
    },
] as const;

export const ROLE_LABELS: Record<StaffRole, string> = {
    owner: 'Dono',
    admin: 'Administrador',
    production: 'Produção',
};
