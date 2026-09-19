import { useForm } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
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
import { password, store, update } from '@/routes/admin/utilizadores';
import type { StaffRow } from '@/types/staff';
import { ROLE_LABELS, STAFF_ROLES } from '@/types/staff';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Null cria; uma linha da listagem edita essa conta. */
    editing: StaffRow | null;
    passwordRules: string;
};

type FormData = {
    name: string;
    email: string;
    role: string;
    password: string;
    password_confirmation: string;
};

/**
 * Criar e editar uma conta da equipa. Ao criar, o dono escreve a password
 * inicial — e a pessoa é obrigada a trocá-la na primeira entrada, porque
 * enquanto alguém mais a souber a conta não é verdadeiramente dela.
 *
 * O papel do dono e o da própria conta não se mudam aqui: o seletor aparece
 * bloqueado, e o servidor recusa na mesma se alguém o forçar.
 */
export function StaffDialog({
    open,
    onOpenChange,
    editing,
    passwordRules,
}: Props) {
    const {
        data,
        setData,
        post,
        patch,
        processing,
        errors,
        reset,
        clearErrors,
    } = useForm<FormData>({
        name: editing?.name ?? '',
        email: editing?.email ?? '',
        role: editing?.role ?? 'production',
        password: '',
        password_confirmation: '',
    });

    const roleLocked =
        editing !== null && (editing.role === 'owner' || editing.isSelf);

    const close = (next: boolean) => {
        if (!next) {
            reset();
            clearErrors();
        }

        onOpenChange(next);
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        const options = { onSuccess: () => close(false) };

        if (editing) {
            patch(update(editing.id).url, options);

            return;
        }

        post(store().url, options);
    };

    return (
        <Dialog open={open} onOpenChange={close}>
            <DialogContent>
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <DialogHeader>
                        <DialogTitle>
                            {editing ? 'Editar conta' : 'Nova conta da equipa'}
                        </DialogTitle>
                        <DialogDescription>
                            {editing
                                ? 'Nome, email e papel. A password redefine-se à parte.'
                                : 'A pessoa entra com esta password e escolhe logo uma sua.'}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2">
                        <Label htmlFor="staff-name">Nome</Label>
                        <Input
                            id="staff-name"
                            value={data.name}
                            onChange={(event) =>
                                setData('name', event.target.value)
                            }
                            autoComplete="off"
                            required
                        />
                        <InputError message={errors.name} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="staff-email">Email</Label>
                        <Input
                            id="staff-email"
                            type="email"
                            value={data.email}
                            onChange={(event) =>
                                setData('email', event.target.value)
                            }
                            autoComplete="off"
                            required
                        />
                        <InputError message={errors.email} />
                    </div>

                    <div className="grid gap-2">
                        <Label>Papel</Label>
                        {roleLocked && editing ? (
                            <p className="text-sm text-muted-foreground">
                                {ROLE_LABELS[editing.role]} — não se muda a
                                partir daqui.
                            </p>
                        ) : (
                            <Select
                                value={data.role}
                                onValueChange={(value) =>
                                    setData('role', value)
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {STAFF_ROLES.map((role) => (
                                        <SelectItem
                                            key={role.value}
                                            value={role.value}
                                        >
                                            {role.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        )}
                        {!roleLocked && (
                            <p className="text-xs text-muted-foreground">
                                {
                                    STAFF_ROLES.find(
                                        (role) => role.value === data.role,
                                    )?.hint
                                }
                            </p>
                        )}
                        <InputError message={errors.role} />
                    </div>

                    {editing === null && (
                        <PasswordFields
                            data={data}
                            setData={setData}
                            errors={errors}
                            passwordRules={passwordRules}
                        />
                    )}

                    <InputError
                        message={(errors as Record<string, string>).staff}
                    />

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => close(false)}
                        >
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing && <Spinner />}
                            {editing ? 'Guardar' : 'Criar conta'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

type PasswordFieldsProps = {
    data: { password: string; password_confirmation: string };
    setData: (key: 'password' | 'password_confirmation', value: string) => void;
    errors: Partial<Record<string, string>>;
    passwordRules: string;
};

function PasswordFields({
    data,
    setData,
    errors,
    passwordRules,
}: PasswordFieldsProps) {
    return (
        <>
            <div className="grid gap-2">
                <Label htmlFor="staff-password">Password inicial</Label>
                <PasswordInput
                    id="staff-password"
                    value={data.password}
                    onChange={(event) =>
                        setData('password', event.target.value)
                    }
                    autoComplete="new-password"
                    passwordrules={passwordRules}
                    required
                />
                <InputError message={errors.password} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor="staff-password-confirmation">
                    Repetir password
                </Label>
                <PasswordInput
                    id="staff-password-confirmation"
                    value={data.password_confirmation}
                    onChange={(event) =>
                        setData('password_confirmation', event.target.value)
                    }
                    autoComplete="new-password"
                    required
                />
            </div>
        </>
    );
}

type ResetProps = {
    target: StaffRow | null;
    onOpenChange: (open: boolean) => void;
    passwordRules: string;
};

/**
 * Redefinir a password de alguém da equipa (esqueceu-se, ou a conta pode ter
 * sido exposta). A pessoa volta a ter de a mudar na entrada seguinte.
 */
export function StaffPasswordDialog({
    target,
    onOpenChange,
    passwordRules,
}: ResetProps) {
    const { data, setData, put, processing, errors, reset, clearErrors } =
        useForm({ password: '', password_confirmation: '' });

    const close = (next: boolean) => {
        if (!next) {
            reset();
            clearErrors();
        }

        onOpenChange(next);
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        if (target) {
            put(password(target.id).url, { onSuccess: () => close(false) });
        }
    };

    return (
        <Dialog open={target !== null} onOpenChange={close}>
            <DialogContent>
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <DialogHeader>
                        <DialogTitle>Redefinir password</DialogTitle>
                        <DialogDescription>
                            {target?.name} entra com esta password e tem de a
                            trocar logo a seguir.
                        </DialogDescription>
                    </DialogHeader>

                    <PasswordFields
                        data={data}
                        setData={setData}
                        errors={errors}
                        passwordRules={passwordRules}
                    />

                    <InputError
                        message={(errors as Record<string, string>).staff}
                    />

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => close(false)}
                        >
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing && <Spinner />}
                            Redefinir
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
