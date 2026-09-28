<?php

namespace App\Http\Requests\Order;

use App\Models\OrderItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductionStatusRequest extends FormRequest
{
    /**
     * Admins e a equipa de producao: e o unico pedido de escrita que a
     * producao faz. Segunda camada por cima do middleware 'production'.
     */
    public function authorize(): bool
    {
        return $this->user()?->canAccessProduction() ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'production_status' => ['required', Rule::in(OrderItem::PRODUCTION_STATUSES)],
            'note' => ['nullable', 'string', 'max:300'],
        ];
    }
}
