<?php

namespace App\Http\Requests\Staff;

use Illuminate\Validation\Rules\Password;

class ResetStaffPasswordRequest extends OwnerFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ];
    }
}
