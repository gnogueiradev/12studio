<?php

namespace App\Http\Requests\Printer;

use App\Models\PrinterProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePrinterProfileRequest extends FormRequest
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
     * O admin tanto cola "0,65" como escreve "0.65"; normalizar aqui deixa o
     * `numeric` passar e o Money::fromDecimal receber sempre a mesma forma.
     */
    protected function prepareForValidation(): void
    {
        $value = $this->input('hourly_rate');

        if (is_string($value) && $value !== '') {
            $this->merge(['hourly_rate' => str_replace(',', '.', $value)]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60', Rule::unique('printer_profiles', 'name')->ignore($this->profileId())],
            // Tecto de 99,99 EUR/h: acima disso e engano de virgula, nao uma
            // impressora. O minimo e 0 e nao 0,01 — uma maquina ja amortizada e
            // emprestada pode mesmo custar zero.
            'hourly_rate' => ['required', 'numeric', 'min:0', 'max:99.99'],
            'notes' => ['nullable', 'string', 'max:255'],
            'is_default' => ['boolean'],
            'active' => ['boolean'],
            'sort_order' => ['integer', 'min:0', 'max:65535'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nome',
            'hourly_rate' => 'custo por hora',
            'notes' => 'notas',
            'is_default' => 'predefinida',
            'sort_order' => 'ordem',
        ];
    }

    /** Null no store; o id da impressora em edicao no update. */
    protected function profileId(): ?int
    {
        $profile = $this->route('printer_profile');

        return $profile instanceof PrinterProfile ? $profile->id : null;
    }
}
