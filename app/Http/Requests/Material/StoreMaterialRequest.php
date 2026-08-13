<?php

namespace App\Http\Requests\Material;

use App\Models\Material;
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

        $colors = $this->input('colors');

        if (is_array($colors)) {
            $this->merge(['colors' => array_map($this->hashHex(...), $colors)]);
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
            /*
             * As cores nascem com o material, so no criar. Cada uma traz nome e
             * hex porque o modal tanto liga uma cor que ja existe como deixa
             * inventar uma ali mesmo — e nesse caso o hex tem mesmo de vir de
             * la. Qual dos dois vale decide-o o ColorPalette::resolve().
             *
             * Sem `unique`: o indice e (material_id, name) e o material ainda
             * nao existe, por isso a unica colisao possivel e o admin escrever
             * o mesmo nome duas vezes no proprio formulario.
             */
            'colors' => ['array'],
            'colors.*.name' => ['required', 'string', 'max:60', 'distinct:ignore_case'],
            // 6 digitos, ou 8 quando a cor tem alfa (a coluna aceita 9 chars).
            'colors.*.hex' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}([0-9a-fA-F]{2})?$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'colors.*.name.required' => 'A cor tem de ter um nome.',
            'colors.*.name.distinct' => 'Escolheste a mesma cor duas vezes.',
            'colors.*.hex.regex' => 'A cor tem de ser um hexadecimal como #1A2B3C.',
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
     * O admin tanto usa o seletor de cor — que devolve sempre "#rrggbb" — como
     * cola "FF7A00" de um site de paletas. Mesmo remendo do StoreColorRequest,
     * aqui aplicado a cada cor da lista.
     */
    private function hashHex(mixed $color): mixed
    {
        if (! is_array($color) || ! isset($color['hex']) || ! is_string($color['hex'])) {
            return $color;
        }

        $hex = trim($color['hex']);

        if ($hex === '' || str_starts_with($hex, '#')) {
            return $color;
        }

        return [...$color, 'hex' => '#'.$hex];
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
