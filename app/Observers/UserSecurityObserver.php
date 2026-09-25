<?php

namespace App\Observers;

use App\Alerts\SecurityAlerts;
use App\Models\User;
use App\Services\ApiKeyService;

/**
 * Revoga todas as chaves de API (e ligacoes OAuth) de uma conta quando ela
 * muda de maneira que as torna suspeitas ou indevidas:
 *
 *   - mudou a password (pode ter sido por suspeita de fuga);
 *   - perdeu o admin, ou mudou de papel;
 *   - foi desativada.
 *
 * O EnsureAdmin ja recusaria um ex-admin a cada pedido; revogar e a segunda
 * camada, e a que sobrevive a um erro de configuracao das rotas.
 */
class UserSecurityObserver
{
    private const SENSITIVE = ['password', 'is_admin', 'staff_role', 'disabled_at'];

    public function __construct(
        private ApiKeyService $keys,
        private SecurityAlerts $alerts,
    ) {}

    public function updated(User $user): void
    {
        if (! $user->wasChanged(self::SENSITIVE)) {
            return;
        }

        $revoked = $this->keys->revokeAll($user);

        // A password tem aviso proprio, venha de onde vier (definicoes, link
        // de reposicao, dono). O resto (papel, desativar) ja e avisado pelo
        // StaffService com o contexto — aqui so as chaves que morreram.
        if ($user->wasChanged('password')) {
            $this->alerts->passwordChanged($user, $revoked);
        } else {
            $this->alerts->keysAutoRevoked($user, $revoked, $user->wasChanged('disabled_at') ? 'Conta desativada' : 'O papel mudou');
        }
    }
}
