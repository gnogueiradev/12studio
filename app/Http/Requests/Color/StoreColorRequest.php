<?php

namespace App\Http\Requests\Color;

use App\Models\Color;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

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
            'name' => ['required', 'string', 'max:60', $this->nameIsFree(...)],
            // 6 digitos, ou 8 quando a cor tem alfa (a coluna aceita 9 chars).
            'hex_color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}([0-9a-fA-F]{2})?$/'],
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
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'hex_color' => 'cor',
            'sort_order' => 'ordem',
        ];
    }

    /**
     * O nome e a identidade da cor e tem de estar livre em toda a tabela — o
     * indice colors_name_unique e global e COLLATE NOCASE.
     *
     * Regra propria e nao `Rule::unique`: o `unique` acrescenta sempre um
     * `name = <valor>` binario, e no SQLite isso deixava passar "preto" ao lado
     * do "Preto" que ja la esta — para rebentar a seguir no INSERT, com um erro
     * de base de dados no ecra de quem preencheu o formulario. Comparar em
     * `lower()` e o que faz a validacao recusar exactamente o que o indice
     * recusaria.
     *
     * Duas excepcoes, e as duas sao o mesmo principio: so colide com o que esta
     * VIVO noutra cor.
     *   - A propria cor em edicao, senao nenhuma se gravava sem lhe mudar o
     *     nome.
     *   - As arquivadas, porque um nome arquivado nao esta ocupado — esta a
     *     espera de voltar. O ColorService restaura essa linha em vez de
     *     inserir uma nova, e recusar aqui era proibir exactamente a operacao
     *     que ele sabe fazer.
     */
    protected function nameIsFree(string $attribute, mixed $value, Closure $fail): void
    {
        $taken = Color::query()
            ->where('is_active', true)
            ->whereRaw('lower(name) = ?', [mb_strtolower(trim((string) $value))])
            ->when($this->colorId() !== null, fn ($query) => $query->whereKeyNot($this->colorId()))
            ->exists();

        if ($taken) {
            $fail('Já existe uma cor com este nome.');
        }
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
