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
     * So o nome e obrigatorio: o admin apanha um cliente ao balcao com um nome
     * e mais nada, e obrigar a inventar um email era ficar com lixo na tabela
     * que a Fase 5 vai usar para o login.
     *
     * Duas excepcoes a essa regra, ambas por causa da faturacao:
     * - uma empresa tem de ter NIF;
     * - a morada entra inteira ou nao entra — `addresses` tem line1,
     *   postal_code e city NOT NULL.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $addressFilled = 'required_with:line1,postal_code,city';

        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->customerId())],
            'customer_type' => ['required', Rule::in(['particular', 'empresa'])],
            'phone' => ['nullable', 'string', 'max:30'],
            'nif' => [
                Rule::requiredIf(fn (): bool => $this->input('customer_type') === 'empresa'),
                'nullable', 'string', 'max:20',
            ],
            'admin_note' => ['nullable', 'string', 'max:2000'],
            'line1' => [$addressFilled, 'nullable', 'string', 'max:190'],
            'line2' => ['nullable', 'string', 'max:190'],
            // Formato PT NNNN-NNN; a coluna tem exatamente 8 caracteres.
            'postal_code' => [$addressFilled, 'nullable', 'string', 'regex:/^\d{4}-\d{3}$/'],
            'city' => [$addressFilled, 'nullable', 'string', 'max:80'],
            'country' => ['nullable', 'string', 'size:2'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'postal_code.regex' => 'O código postal tem de ter o formato 1234-567.',
            'nif.required' => 'Uma empresa precisa de NIF para poder ser faturada.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nome',
            'customer_type' => 'tipo de cliente',
            'admin_note' => 'notas internas',
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
