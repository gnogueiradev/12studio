<?php

namespace App\Http\Requests\Setting;

use App\Models\Setting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSettingsRequest extends FormRequest
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
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // As chaves, nao os valores: o CURRENCIES e um mapa codigo => nome
            // e o que se guarda (e valida) e o codigo ISO.
            'currency' => ['required', 'string', Rule::in(array_keys(Setting::CURRENCIES))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['currency' => 'moeda'];
    }
}
