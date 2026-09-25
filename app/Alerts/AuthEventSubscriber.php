<?php

namespace App\Alerts;

use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Events\Dispatcher;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;

/**
 * Os eventos de autenticacao do Laravel, do Fortify e das passkeys, para o
 * #seguranca. So o guard `web` (o backoffice): o /mcp autentica por token e
 * tem o seu proprio rasto.
 */
class AuthEventSubscriber
{
    public function __construct(
        private SecurityAlerts $alerts,
    ) {}

    public function handleLogin(Login $event): void
    {
        if ($event->guard === config('fortify.guard') && $event->user instanceof User) {
            $this->alerts->login($event->user, (bool) $event->remember);
        }
    }

    /**
     * As credenciais trazem a password tentada: daqui so sai o email.
     */
    public function handleFailed(Failed $event): void
    {
        if ($event->guard !== config('fortify.guard')) {
            return;
        }

        $email = $event->credentials[Fortify::username()] ?? null;

        $this->alerts->loginFailed(
            is_string($email) ? $email : null,
            $event->user instanceof User ? $event->user : null,
        );
    }

    public function handleLockout(Lockout $event): void
    {
        $email = $event->request->input(Fortify::username());

        $this->alerts->lockout(is_string($email) ? $email : null);
    }

    public function handleTwoFactorConfirmed(TwoFactorAuthenticationConfirmed $event): void
    {
        $this->alerts->twoFactor($event->user, true);
    }

    public function handleTwoFactorDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        $this->alerts->twoFactor($event->user, false);
    }

    public function handlePasskeyRegistered(PasskeyRegistered $event): void
    {
        if ($event->user instanceof User) {
            $this->alerts->passkey($event->user, $event->passkey->name ?? null, true);
        }
    }

    public function handlePasskeyDeleted(PasskeyDeleted $event): void
    {
        if ($event->user instanceof User) {
            $this->alerts->passkey($event->user, $event->passkey->name ?? null, false);
        }
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'handleLogin',
            Failed::class => 'handleFailed',
            Lockout::class => 'handleLockout',
            TwoFactorAuthenticationConfirmed::class => 'handleTwoFactorConfirmed',
            TwoFactorAuthenticationDisabled::class => 'handleTwoFactorDisabled',
            PasskeyRegistered::class => 'handlePasskeyRegistered',
            PasskeyDeleted::class => 'handlePasskeyDeleted',
        ];
    }
}
