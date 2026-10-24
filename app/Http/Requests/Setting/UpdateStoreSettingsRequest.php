<?php

namespace App\Http\Requests\Setting;

use App\Http\Requests\AdminFormRequest;
use App\Services\StoreSettings;
use Closure;

class UpdateStoreSettingsRequest extends AdminFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'open' => ['required', 'boolean'],
            'contact_email' => ['nullable', 'string', 'email:rfc', 'max:120'],
            // Algarismos, espacos, hifens, parenteses e o "+" do indicativo —
            // vai num link tel: e tem de ser marcavel.
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9\s\-()]{6,}$/'],
            'address' => ['nullable', 'string', 'max:200'],
            'opening_hours' => ['nullable', 'string', 'max:120'],
            // Ate uma semana. "Respondemos normalmente em 0 horas" nao promete
            // nada que se cumpra.
            'response_hours' => ['nullable', 'integer', 'min:1', 'max:168'],
            'social' => ['nullable', 'array'],
            'locales' => ['nullable', 'array'],
            'locales.*' => ['string', 'in:'.implode(',', array_keys((array) config('locales.available')))],
        ];

        foreach (StoreSettings::SOCIAL_NETWORKS as $network => [, $hosts]) {
            $rules["social.$network"] = [
                'nullable', 'string', 'max:255', 'url:https', $this->onHosts($hosts),
            ];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = [
            'open' => 'montra aberta',
            'contact_email' => 'email de contacto',
            'phone' => 'telefone',
            'address' => 'morada',
            'opening_hours' => 'horário',
            'response_hours' => 'tempo de resposta',
            'locales' => 'idiomas',
            'locales.*' => 'idioma',
        ];

        foreach (StoreSettings::SOCIAL_NETWORKS as $network => [$label]) {
            $attributes["social.$network"] = "link do $label";
        }

        return $attributes;
    }

    /**
     * O link tem de ser da rede do proprio campo (www. e subdominios incluidos).
     *
     * @param  array<int, string>  $hosts
     */
    private function onHosts(array $hosts): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($hosts): void {
            $host = strtolower((string) parse_url((string) $value, PHP_URL_HOST));

            foreach ($hosts as $allowed) {
                if ($host === $allowed || str_ends_with($host, ".$allowed")) {
                    return;
                }
            }

            $fail("O :attribute tem de ser um endereço de {$hosts[0]}.");
        };
    }
}
