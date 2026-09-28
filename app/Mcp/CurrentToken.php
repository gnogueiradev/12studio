<?php

namespace App\Mcp;

use App\Models\User;
use Laravel\Passport\AccessToken;
use Laravel\Passport\Passport;
use Laravel\Passport\Token;

/**
 * O token do Passport com que o pedido atual entrou, com tipos a serio.
 *
 * O `$user->token()` devolve um AccessToken (os atributos do JWT) que
 * reencaminha o resto, por magia, para a linha de oauth_access_tokens. Aqui
 * isso fica explicito: o id vem do JWT, a linha vem da BD.
 */
final class CurrentToken
{
    public static function id(?User $user): ?string
    {
        $token = $user?->token();

        if (! $token instanceof AccessToken) {
            return null;
        }

        $id = $token->oauth_access_token_id;

        return $id !== '' ? $id : null;
    }

    public static function model(?User $user): ?Token
    {
        $id = self::id($user);

        if ($id === null) {
            return null;
        }

        /** @var Token|null $token */
        $token = Passport::token()->newQuery()->with('client')->find($id);

        return $token;
    }
}
