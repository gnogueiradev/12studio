<?php

namespace App\Http\Requests\Order;

use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrderPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'payment_status' => ['required', Rule::in(Order::PAYMENT_STATUSES)],
            // Sem a Etsy: so a importacao da esse metodo a uma encomenda — e
            // a que ja o tem pode mante-lo (o formulario manda sempre o campo).
            'payment_method' => ['nullable', Rule::in([
                ...Order::MANUAL_PAYMENT_METHODS,
                ...($this->route('order') instanceof Order && $this->route('order')->isFromEtsy() ? ['etsy'] : []),
            ])],
            'note' => ['nullable', 'string', 'max:300'],
        ];
    }
}
