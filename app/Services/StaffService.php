<?php

namespace App\Services;

use App\Alerts\SecurityAlerts;
use App\Mail\StaffAccountChangedMail;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Contas da equipa (admins e producao), geridas pelo dono.
 *
 * `is_admin`, `staff_role` e `is_owner` nao sao fillable: sao atribuidos aqui,
 * um a um, e em mais sitio nenhum. O `is_owner` nem aqui — so o comando
 * `users:make-owner`.
 *
 * Cada mudanca manda um email ao dono, e um alerta ao #seguranca. Parece
 * redundante (foi ele que clicou), mas e o rasto que sobra se a sessao dele
 * alguma vez for de outra pessoa.
 */
class StaffService
{
    public function __construct(
        private SecurityAlerts $alerts,
    ) {}

    /**
     * @param  array{name: string, email: string, role: string, password: string}  $data
     */
    public function create(array $data, User $by): User
    {
        $staff = DB::transaction(function () use ($data, $by): User {
            $staff = new User([
                'name' => $data['name'],
                'email' => Str::lower($data['email']),
                'password' => $data['password'],
            ]);

            $this->applyRole($staff, $data['role']);
            // O dono responde pela conta: nao ha email de verificacao a
            // esperar, e a pessoa tem de trocar a password no primeiro login.
            $staff->email_verified_at = now()->toImmutable();
            $staff->must_change_password = true;
            $staff->created_by_user_id = $by->getKey();
            $staff->save();

            return $staff;
        });

        $this->notify($staff, $by, "Conta criada ({$this->roleLabel($staff)})");

        return $staff;
    }

    /**
     * @param  array{name: string, email: string, role: string}  $data
     */
    public function update(User $staff, array $data, User $by): User
    {
        $previousRole = $staff->role();

        // O papel `owner` so volta no formulario da propria conta do dono, tal
        // como saiu; pedi-lo para outra conta nao promove ninguem.
        if ($previousRole !== 'owner' && ! in_array($data['role'], User::STAFF_ROLES, true)) {
            throw ValidationException::withMessages(['role' => 'Papel inválido.']);
        }

        if ($previousRole !== $data['role']) {
            $this->ensureCanChange($staff, $by, 'mudar o papel de');
        }

        $staff->fill([
            'name' => $data['name'],
            'email' => Str::lower($data['email']),
        ]);

        if ($previousRole !== 'owner') {
            $this->applyRole($staff, $data['role']);
        }

        $staff->save();

        if ($previousRole !== $staff->role()) {
            // Menos poderes = as sessoes "lembrar-me" antigas deixam de valer.
            $this->rotateRememberToken($staff);
            $this->notify($staff, $by, "Papel mudado para {$this->roleLabel($staff)}");
        }

        return $staff;
    }

    public function resetPassword(User $staff, string $password, User $by): void
    {
        $this->ensureCanChange($staff, $by, 'redefinir a password de');

        $staff->password = $password;
        $staff->must_change_password = true;
        $staff->save();

        $this->rotateRememberToken($staff);
        // O aviso no Discord vem do UserSecurityObserver (password mudada),
        // como para qualquer outra mudanca de password.
        $this->notify($staff, $by, 'Password redefinida pelo dono', alert: false);
    }

    public function disable(User $staff, User $by): void
    {
        $this->ensureCanChange($staff, $by, 'desativar');

        $staff->disabled_at = now()->toImmutable();
        $staff->save();

        // A sessao aberta morre no proximo pedido (EnsureAccountUsable); o
        // cookie "lembrar-me" morre aqui.
        $this->rotateRememberToken($staff);
        $this->notify($staff, $by, 'Conta desativada');
    }

    public function enable(User $staff, User $by): void
    {
        $staff->disabled_at = null;
        $staff->save();

        $this->notify($staff, $by, 'Conta reativada');
    }

    private function applyRole(User $staff, string $role): void
    {
        $staff->is_admin = $role === User::ROLE_ADMIN;
        $staff->staff_role = $role === User::ROLE_PRODUCTION ? User::ROLE_PRODUCTION : null;
    }

    /**
     * O dono nao se tira a si proprio de la, e ninguem mexe no dono: sem
     * esta regra, um clique errado deixava a loja sem ninguem que gerisse a
     * equipa — e a unica saida seria a consola do servidor.
     */
    private function ensureCanChange(User $staff, User $by, string $action): void
    {
        if ($staff->is($by)) {
            throw ValidationException::withMessages([
                'staff' => "Não podes {$action} a tua própria conta.",
            ]);
        }

        if ($staff->is_owner) {
            throw ValidationException::withMessages([
                'staff' => "Não é possível {$action} a conta do dono.",
            ]);
        }
    }

    private function rotateRememberToken(User $staff): void
    {
        $staff->setRememberToken(Str::random(60));
        $staff->save();
    }

    private function roleLabel(User $staff): string
    {
        return match ($staff->role()) {
            'owner' => 'dono',
            User::ROLE_ADMIN => 'administrador',
            User::ROLE_PRODUCTION => 'produção',
            default => 'cliente',
        };
    }

    private function notify(User $staff, User $by, string $change, bool $alert = true): void
    {
        if ($alert) {
            $this->alerts->staffChanged($staff, $by, $change);
        }

        User::query()
            ->where('is_owner', true)
            ->whereNotNull('email')
            ->get()
            ->each(fn (User $owner) => Mail::to($owner)->send(
                new StaffAccountChangedMail($staff, $by, $change),
            ));
    }
}
