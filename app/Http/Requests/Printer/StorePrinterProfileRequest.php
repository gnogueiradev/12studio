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
     * `numeric` passar e o Money/Micros::fromDecimal receber sempre a mesma
     * forma. So os dois campos decimais: a potencia e a vida util sao inteiros.
     */
    protected function prepareForValidation(): void
    {
        foreach (['purchase_price', 'maintenance_rate'] as $field) {
            $value = $this->input($field);

            if (is_string($value) && $value !== '') {
                $this->merge([$field => str_replace(',', '.', $value)]);
            }
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60', Rule::unique('printer_profiles', 'name')->ignore($this->profileId())],
            // Potencia media durante a impressao. Zero e legitimo (quem nao
            // quiser contar energia poe zero); o tecto de 5000 W apanha quem
            // escreveu a potencia em miliwatts ou confundiu com o consumo do
            // quadro todo.
            'average_power_watts' => ['required', 'integer', 'min:0', 'max:5000'],
            // Tecto de 99.999,99 EUR: acima disso e engano de virgula, nao uma
            // impressora. Zero e legitimo — uma maquina oferecida ou ja
            // amortizada nao tem de continuar a amortizar-se.
            'purchase_price' => ['required', 'numeric', 'min:0', 'max:99999.99'],
            // MINIMO 1, e nao 0: esta coluna e um DIVISOR. Zero horas de vida
            // util e uma divisao por zero, e o calculador tem uma guarda para
            // ela, mas o formulario nao tem de a deixar chegar la.
            'lifetime_hours' => ['required', 'integer', 'min:1', 'max:100000'],
            // Quatro casas porque e um valor por hora que cabe abaixo do
            // centimo: 0,0350 EUR/h e uma reserva plausivel.
            'maintenance_rate' => ['required', 'numeric', 'min:0', 'max:99.9999'],
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
            'average_power_watts' => 'potência média',
            'purchase_price' => 'preço de compra',
            'lifetime_hours' => 'vida útil',
            'maintenance_rate' => 'manutenção por hora',
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
