<?php

namespace App\Http\Requests\Product;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    /**
     * Segunda camada de defesa por cima do middleware 'admin'.
     */
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
            'name' => ['required', 'string', 'max:120'],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'description' => ['nullable', 'string', 'max:10000'],
            'status' => ['required', Rule::in(Product::STATUSES)],
            'featured' => ['boolean'],
            'vat_rate' => ['required', 'integer', 'min:0', 'max:100'],
            'fulfillment_mode' => ['required', Rule::in(Product::FULFILLMENT_MODES)],
            'production_time_days' => ['nullable', 'integer', 'min:0', 'max:60'],
            'allow_backorder' => ['boolean'],
            'max_open_production_qty' => ['nullable', 'integer', 'min:1', 'max:65535'],
        ];
    }
}
