<?php

namespace App\Http\Requests\Order;

use App\Http\Requests\AdminFormRequest;
use App\Support\Money;

class PreviewOrderTotalsRequest extends AdminFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'email' => ['nullable', 'string', 'max:255'],
            'promo_code' => ['nullable', 'string', 'max:40'],
            'shipping_price' => ['nullable', 'string', 'max:20'],
            'items' => ['array', 'max:100'],
            'items.*.unit_price' => ['nullable', 'string', 'max:20'],
            'items.*.qty' => ['nullable', 'integer', 'min:0', 'max:999'],
        ];
    }

    public function subtotalCents(): int
    {
        $subtotal = 0;

        foreach ($this->array('items') as $item) {
            $subtotal += Money::fromDecimal((string) ($item['unit_price'] ?? '0')) * (int) ($item['qty'] ?? 0);
        }

        return $subtotal;
    }
}
