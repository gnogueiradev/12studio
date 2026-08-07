<?php

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOrderDetailsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * Campos livres do admin. Nada aqui mexe em estados — isso passa
     * sempre pelo OrderService.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'admin_note' => ['nullable', 'string', 'max:2000'],
            'tracking_number' => ['nullable', 'string', 'max:100'],
            'tracking_url' => ['nullable', 'url', 'max:255'],
            'shipping_method_name' => ['nullable', 'string', 'max:120'],
        ];
    }
}
