<?php

namespace App\Http\Requests\Color;

use App\Models\Color;
use Illuminate\Contracts\Database\Query\Builder;
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
            // A cor grava-se em leque: uma linha `colors` por material
            // escolhido, todas com este nome e este hex.
            'material_ids' => ['required', 'array', 'min:1'],
            'material_ids.*' => ['integer', Rule::exists('materials', 'id')],
            /*
             * O nome tem de estar livre em CADA material escolhido — o indice
             * colors_material_name_unique e por material, nao global.
             *
             * Duas excepcoes, e as duas sao o mesmo principio: so colide com o
             * que esta VIVO noutra cor.
             *   - As linhas do proprio grupo, porque sao elas que estao a ser
             *     gravadas. Sem isto nenhuma cor se editava sem lhe mudar o
             *     nome.
             *   - As arquivadas, porque um nome arquivado nao esta ocupado —
             *     esta a espera de voltar. O ColorService restaura essa linha
             *     em vez de inserir uma nova, e recusar aqui era proibir
             *     exactamente a operacao que ele sabe fazer.
             */
            'name' => [
                'required', 'string', 'max:60',
                Rule::unique('colors', 'name')
                    ->whereIn('material_id', $this->materialIds())
                    ->where(fn (Builder $query) => $query
                        ->where('is_active', true)
                        ->whereNotIn('id', $this->groupColorIds())),
            ],
            // 6 digitos, ou 8 quando a cor tem alfa (a coluna aceita 9 chars).
            'hex_color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}([0-9a-fA-F]{2})?$/'],
            'price_per_kg' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'hex_color.regex' => 'A cor tem de ser um hexadecimal como #1A2B3C.',
            'name.unique' => 'Um dos materiais escolhidos já tem uma cor com este nome.',
            'material_ids.required' => 'Escolhe pelo menos um material onde esta cor existe.',
            'material_ids.min' => 'Escolhe pelo menos um material onde esta cor existe.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'material_ids' => 'materiais',
            'hex_color' => 'cor',
            'price_per_kg' => 'preço por kg',
        ];
    }

    /**
     * @return array<int, int>
     */
    protected function materialIds(): array
    {
        $ids = $this->input('material_ids');

        return is_array($ids) ? array_map('intval', array_values($ids)) : [];
    }

    /**
     * Linhas que ja pertencem a esta cor. Vazio no store; no update sai do
     * representante que a rota vinculou, pelo nome — que e o que agrupa.
     *
     * @return array<int, int>
     */
    protected function groupColorIds(): array
    {
        $color = $this->route('color');

        if (! $color instanceof Color) {
            return [];
        }

        return Color::query()
            ->where('name', $color->name)
            ->pluck('id')
            ->all();
    }
}
