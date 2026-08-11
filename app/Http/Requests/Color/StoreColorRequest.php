<?php

namespace App\Http\Requests\Color;

use App\Models\Color;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreColorRequest extends FormRequest
{
    /**
     * Segunda camada de defesa por cima do middleware 'admin' — middleware
     * sozinho nao e seguranca (padrao do plano).
     */
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    protected function prepareForValidation(): void
    {
        $price = $this->input('price_per_kg');

        if (is_string($price) && $price !== '') {
            $this->merge(['price_per_kg' => str_replace(',', '.', $price)]);
        }

        // O <input type="color"> devolve sempre minusculas com #, mas o admin
        // tambem cola "FF7A00" de um site de paletas.
        $hex = $this->input('hex_color');

        if (is_string($hex) && $hex !== '' && ! str_starts_with($hex, '#')) {
            $this->merge(['hex_color' => '#'.$hex]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'material_id' => ['required', 'integer', Rule::exists('materials', 'id')],
            // Unico DENTRO do material: "Preto" existe em PLA e em PETG e sao
            // linhas distintas (indice colors_material_name_unique).
            'name' => [
                'required', 'string', 'max:60',
                Rule::unique('colors', 'name')
                    ->where('material_id', (int) $this->input('material_id'))
                    ->ignore($this->colorId()),
            ],
            // 6 digitos, ou 8 quando a cor tem alfa (a coluna aceita 9 chars).
            'hex_color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}([0-9a-fA-F]{2})?$/'],
            'price_per_kg' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
            'is_active' => ['boolean'],
            'sort_order' => ['integer', 'min:0', 'max:65535'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'hex_color.regex' => 'A cor tem de ser um hexadecimal como #1A2B3C.',
            'name.unique' => 'Este material já tem uma cor com este nome.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'material_id' => 'material',
            'hex_color' => 'cor',
            'price_per_kg' => 'preço por kg',
            'sort_order' => 'ordem',
        ];
    }

    /**
     * Null no store; o id da cor em edicao no update.
     */
    protected function colorId(): ?int
    {
        $color = $this->route('color');

        return $color instanceof Color ? $color->id : null;
    }
}
