<?php

namespace App\Http\Requests\Customer;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerRequest extends FormRequest
{
    /**
     * Segunda camada de defesa por cima do middleware 'admin'.
     */
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * Telefone e NIF vivem na morada (`addresses`), nao em `users` — a
     * tabela de utilizadores do Fortify fica intacta.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->customerId())],
            'line1' => ['required', 'string', 'max:190'],
            'line2' => ['nullable', 'string', 'max:190'],
            // Formato PT NNNN-NNN; a coluna tem exatamente 8 caracteres.
            'postal_code' => ['required', 'string', 'regex:/^\d{4}-\d{3}$/'],
            'city' => ['required', 'string', 'max:80'],
            'country' => ['required', 'string', 'size:2'],
            'phone' => ['nullable', 'string', 'max:30'],
            'nif' => ['nullable', 'string', 'max:20'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'postal_code.regex' => 'O código postal tem de ter o formato 1234-567.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nome',
            'line1' => 'morada',
            'line2' => 'complemento',
            'postal_code' => 'código postal',
            'city' => 'localidade',
            'country' => 'país',
            'phone' => 'telefone',
        ];
    }

    /**
     * Null no store; o id do cliente em edicao no update.
     */
    protected function customerId(): ?int
    {
        $customer = $this->route('customer');

        return $customer instanceof User ? $customer->id : null;
    }
}
