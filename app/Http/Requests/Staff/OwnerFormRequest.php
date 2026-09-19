<?php

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Pedidos da gestao da equipa: so o dono. Segunda camada por cima do
 * middleware `owner`, como o AdminFormRequest faz para o `admin`.
 */
class OwnerFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isOwner() ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [];
    }
}
