<?php

namespace App\Http\Requests\Material;

use App\Models\Material;
use App\Support\FilamentPalette;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMaterialRequest extends FormRequest
{
    /**
     * Segunda camada de defesa por cima do middleware 'admin' — middleware
     * sozinho nao e seguranca (padrao do plano).
     */
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * O admin tanto cola "21,90" como escreve "21.90"; normalizar aqui deixa
     * o `numeric` passar e o Money::fromDecimal receber sempre a mesma forma.
     */
    protected function prepareForValidation(): void
    {
        $value = $this->input('price_per_kg');

        if (is_string($value) && $value !== '') {
            $this->merge(['price_per_kg' => str_replace(',', '.', $value)]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60', Rule::unique('materials', 'name')->ignore($this->materialId())],
            'family' => ['nullable', 'string', Rule::in(Material::FAMILIES)],
            'supplier' => ['nullable', 'string', 'max:60'],
            'price_per_kg' => ['required', 'numeric', 'min:0', 'max:9999.99'],
            'spools_in_stock' => ['integer', 'min:0', 'max:65535'],
            'min_spools' => ['integer', 'min:0', 'max:65535'],
            'active' => ['boolean'],
            'sort_order' => ['integer', 'min:0', 'max:65535'],
            // Presets da paleta, so no criar. O Rule::in fecha a porta a um
            // nome inventado — o servico procura o hex por este nome e uma cor
            // sem hex conhecido nao se grava.
            'colors' => ['array'],
            'colors.*' => ['string', Rule::in(FilamentPalette::names())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'family' => 'família',
            'supplier' => 'fornecedor',
            'price_per_kg' => 'preço por kg',
            'spools_in_stock' => 'bobines em stock',
            'min_spools' => 'stock mínimo',
            'sort_order' => 'ordem',
            'colors' => 'cores',
        ];
    }

    /**
     * Null no store; o id do material em edicao no update.
     */
    protected function materialId(): ?int
    {
        $material = $this->route('material');

        return $material instanceof Material ? $material->id : null;
    }
}
