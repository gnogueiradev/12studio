<?php

namespace App\Http\Requests\Tag;

use App\Models\Tag;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTagRequest extends FormRequest
{
    /**
     * Segunda camada de defesa por cima do middleware 'admin'.
     */
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * Sem `Rule::unique` no nome, ao contrario dos materiais e das cores.
     *
     * A identidade de uma etiqueta e o par `(scope, slug)`, e o slug e derivado
     * — "Natal" e "natal" sao a mesma coisa e um unique binario sobre o nome
     * deixava passar as duas. Mas nem por isso a colisao e um erro: criar um
     * nome que ja existe devolve a etiqueta que la esta, e renomear para um nome
     * ocupado funde as duas. Recusar era deixar o admin com o engano que veio
     * aqui corrigir.
     *
     * O `regex` e o unico travao: um nome que nao tenha uma letra ou um digito
     * nao da slug nenhum, e uma etiqueta sem slug nao tem identidade.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'scope' => ['required', Rule::in(Tag::SCOPES)],
            'name' => ['required', 'string', 'max:60', 'regex:/[\p{L}\p{N}]/u'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.regex' => 'A etiqueta precisa de ter pelo menos uma letra ou um número.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'scope' => 'âmbito',
            'name' => 'nome',
        ];
    }
}
