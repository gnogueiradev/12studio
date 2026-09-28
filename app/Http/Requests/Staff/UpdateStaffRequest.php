<?php

namespace App\Http\Requests\Staff;

use App\Models\User;
use Illuminate\Validation\Rule;

class UpdateStaffRequest extends OwnerFormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim((string) $this->input('email')))]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $staff = $this->route('staff');

        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($staff instanceof User ? $staff->getKey() : null),
            ],
            // O dono aparece com o papel `owner`, que o formulario manda de
            // volta tal e qual; o StaffService nunca o aplica.
            'role' => ['required', Rule::in([...User::STAFF_ROLES, 'owner'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'Já existe uma conta com este email (pode ser de um cliente).',
        ];
    }
}
