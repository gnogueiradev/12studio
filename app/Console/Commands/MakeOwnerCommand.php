<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Da o papel de dono (gerir a equipa) a uma conta que ja existe.
 *
 * E um comando, e nao uma migracao, de proposito: a BD de producao tem dados,
 * e mudar quem manda na loja e uma escrita que se faz a vista, por quem tem a
 * consola — nunca escondida num deploy. O dono e sempre admin.
 */
class MakeOwnerCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'users:make-owner {email : Email de uma conta que ja existe}';

    /**
     * @var string
     */
    protected $description = 'Torna uma conta existente dona da loja (admin + gere a equipa)';

    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("Não há nenhuma conta com o email {$email}.");

            return self::FAILURE;
        }

        if ($user->isDisabled()) {
            $this->error("A conta {$email} está desativada — reativa-a primeiro.");

            return self::FAILURE;
        }

        $user->is_admin = true;
        $user->is_owner = true;
        $user->staff_role = null;
        $user->save();

        $this->info("{$user->name} ({$email}) é agora dono da loja.");

        return self::SUCCESS;
    }
}
