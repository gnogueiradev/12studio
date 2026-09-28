<?php

namespace App\Http\Requests\Alert;

use App\Alerts\AlertWebhooks;
use App\Http\Requests\AdminFormRequest;

class UpdateAlertWebhookRequest extends AdminFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'url' => ['required', 'string', 'max:500', 'regex:'.AlertWebhooks::PATTERN],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'url.regex' => 'Isto não é um URL de webhook do Discord — tem de começar por https://discord.com/api/webhooks/.',
        ];
    }

    /**
     * Quem copia do Discord traz as vezes um espaco ou uma quebra de linha.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('url'))) {
            $this->merge(['url' => trim($this->input('url'))]);
        }
    }
}
