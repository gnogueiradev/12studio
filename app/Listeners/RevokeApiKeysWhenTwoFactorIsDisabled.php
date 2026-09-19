<?php

namespace App\Listeners;

use App\Services\ApiKeyService;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;

/**
 * As chaves so se criam com segundo fator ativo. Quem o desliga deixa de
 * cumprir a condicao — e as chaves que tinha vao com ele.
 */
class RevokeApiKeysWhenTwoFactorIsDisabled
{
    public function __construct(
        private ApiKeyService $keys,
    ) {}

    public function handle(TwoFactorAuthenticationDisabled $event): void
    {
        if (! $event->user->passkeys()->exists()) {
            $this->keys->revokeAll($event->user);
        }
    }
}
