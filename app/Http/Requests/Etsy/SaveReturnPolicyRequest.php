<?php

namespace App\Http\Requests\Etsy;

use App\Http\Requests\AdminFormRequest;
use Illuminate\Validation\Rule;

/**
 * Uma politica de devolucao da loja na Etsy. Nao e obrigatoria para lojas da
 * UE, mas quem aceita devolucoes ou trocas tem de dizer em quanto tempo.
 */
class SaveReturnPolicyRequest extends AdminFormRequest
{
    /** Os prazos, em dias, que a Etsy aceita. */
    public const DEADLINES = [7, 14, 21, 30, 45, 60, 90];

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'policy_id' => ['nullable', 'integer', 'min:1'],
            'accepts_returns' => ['required', 'boolean'],
            'accepts_exchanges' => ['required', 'boolean'],
            'return_deadline' => [
                'nullable',
                'integer',
                Rule::in(self::DEADLINES),
                Rule::requiredIf(fn (): bool => $this->boolean('accepts_returns') || $this->boolean('accepts_exchanges')),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'return_deadline.required' => 'Quem aceita devoluções ou trocas tem de indicar o prazo, em dias.',
            'return_deadline.in' => 'O prazo tem de ser um destes: '.implode(', ', self::DEADLINES).' dias.',
        ];
    }
}
