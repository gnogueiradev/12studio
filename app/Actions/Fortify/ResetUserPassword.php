<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    /**
     * Validate and reset the user's forgotten password.
     *
     * @param  array<string, string>  $input
     */
    public function reset(User $user, array $input): void
    {
        // So a equipa recupera a password por aqui. Um cliente nunca recebe o
        // email, mas o broker grava o token na mesma (e havera tokens de antes
        // desta regra). A mensagem e a de um token invalido, a mesma de quem
        // o inventou.
        if (! $user->isStaff()) {
            throw ValidationException::withMessages([
                'email' => [trans('passwords.token')],
            ]);
        }

        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        $user->forceFill([
            'password' => $input['password'],
        ])->save();
    }
}
