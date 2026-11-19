<?php

namespace App\Http\Requests\PromoCode;

use App\Http\Requests\AdminFormRequest;
use App\Models\PromoCode;
use App\Support\Money;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SavePromoCodeRequest extends AdminFormRequest
{
    protected function prepareForValidation(): void
    {
        $promoCode = $this->promoCode();

        $this->merge([
            'code' => PromoCode::normalizeCode((string) $this->input('code')),
            // Os automaticos ficam presos ao email: mudar o de boas-vindas
            // libertava o email original para receber segundo codigo, e o de
            // voltar a comprar e de quem comprou.
            'email' => $promoCode?->isAutomatic() === true
                ? $promoCode->email
                : PromoCode::normalizeEmail($this->input('email')),
            'value' => str_replace(',', '.', (string) $this->input('value')),
            'min_subtotal' => str_replace(',', '.', (string) $this->input('min_subtotal')),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $promoCode = $this->promoCode();

        return [
            'code' => [
                'required', 'string', 'regex:/^[A-Z0-9_-]{3,40}$/',
                Rule::unique('promo_codes', 'code')->ignore($promoCode?->getKey()),
            ],
            'type' => ['required', Rule::in(PromoCode::TYPES)],
            'value' => ['required', 'numeric', 'min:0.01', 'max:99999.99'],
            'min_subtotal' => ['nullable', 'numeric', 'min:0', 'max:99999.99'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', ...($this->filled('starts_at') ? ['after:starts_at'] : [])],
            // Nao desce abaixo do que ja foi gasto.
            'max_uses' => ['nullable', 'integer', 'min:'.max(1, $promoCode->uses_count ?? 1), 'max:1000000'],
            'single_use_per_email' => ['boolean'],
            // Um codigo automatico nao muda de canal, e nenhum outro passa a ser.
            'channel' => ['required', Rule::in($promoCode?->isAutomatic() === true
                ? [$promoCode->channel]
                : PromoCode::MANUAL_CHANNELS)],
            'email' => ['nullable', 'email', 'max:255'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $value = (float) $this->input('value');

                if ($this->input('type') === PromoCode::TYPE_PERCENT && ($value > 100 || floor($value) !== $value)) {
                    $validator->errors()->add('value', 'A percentagem tem de ser um número inteiro de 1 a 100.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'O código tem de ter 3 a 40 letras, números, hífens ou underscores.',
            'expires_at.after' => 'A validade tem de ser depois do início.',
        ];
    }

    /**
     * O formulario fala em euros; a tabela em centimos.
     *
     * @return array<string, mixed>
     */
    public function attributesForModel(): array
    {
        $data = $this->validated();

        return [
            ...collect($data)->except('min_subtotal')->all(),
            'value' => $data['type'] === PromoCode::TYPE_PERCENT
                ? (int) $data['value']
                : Money::fromDecimal((string) $data['value']),
            'min_subtotal_cents' => Money::fromDecimal((string) ($data['min_subtotal'] ?? '0')),
        ];
    }

    private function promoCode(): ?PromoCode
    {
        $promoCode = $this->route('promoCode');

        return $promoCode instanceof PromoCode ? $promoCode : null;
    }
}
