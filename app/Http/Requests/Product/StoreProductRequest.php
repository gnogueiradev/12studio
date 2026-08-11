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
            // Vazio = gerado a partir do nome (ProductService). Quando vem
            // preenchido, a unicidade e garantida la com sufixo numerico —
            // aqui so se guarda a forma.
            'slug' => ['nullable', 'string', 'max:140', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'description' => ['nullable', 'string', 'max:10000'],
            'tags' => ['array', 'max:20'],
            'tags.*' => ['string', 'max:60'],
            'status' => ['required', Rule::in(Product::STATUSES)],
            'featured' => ['boolean'],
            'vat_rate' => ['required', 'integer', 'min:0', 'max:100'],
            'fulfillment_mode' => ['required', Rule::in(Product::FULFILLMENT_MODES)],
            'production_time_days' => ['nullable', 'integer', 'min:0', 'max:60'],
            'allow_backorder' => ['boolean'],
            'max_open_production_qty' => ['nullable', 'integer', 'min:1', 'max:65535'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.regex' => 'O endereço só pode ter minúsculas, números e hífens (ex.: vaso-ondulado).',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'slug' => 'endereço',
            'category_id' => 'categoria',
            'vat_rate' => 'IVA',
            'tags' => 'etiquetas',
        ];
    }
}
