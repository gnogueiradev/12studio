<?php

namespace App\Http\Requests\Notify;

use Illuminate\Foundation\Http\FormRequest;

class StoreNotifyRequest extends FormRequest
{
    /**
     * Rota publica: a landing "em breve" e o unico sitio da aplicacao que um
     * visitante sem conta pode submeter. O travao aqui e o throttle da rota,
     * nao a autorizacao.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            // "Ana@Gmail.com" e "ana@gmail.com" sao a mesma pessoa. Normalizar
            // antes de validar faz o indice unico da tabela cumprir o que
            // parece prometer.
            $this->merge(['email' => mb_strtolower(trim($email))]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // 'email:rfc' e nao 'email:rfc,dns': a verificacao de DNS faz uma
            // consulta de rede no meio do pedido e falha em redes que bloqueiam
            // resolucao — uma landing de lancamento nao pode ficar refem disso.
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'Escreve o teu email para te avisarmos.',
            'email.email' => 'Isto não parece um email válido.',
        ];
    }
}
