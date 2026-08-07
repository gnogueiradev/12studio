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
            'payment_method' => ['nullable', Rule::in(Order::PAYMENT_METHODS)],
            'note' => ['nullable', 'string', 'max:300'],
        ];
    }
}
