<?php

namespace App\Http\Requests\Order;

use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateOrderAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    protected function prepareForValidation(): void
    {
        // O admin escreve "-2,50" e a regra `numeric` nao sabe ler virgulas.
        // A leitura a serio (com "€", separadores de milhar e o resto) e do
        // Money::fromDecimal, ja no controller — isto so desbloqueia a
        // validacao.
        if (is_string($this->input('adjustment_price'))) {
            $this->merge([
                'adjustment_price' => str_replace(',', '.', (string) $this->input('adjustment_price')),
            ]);
        }
    }

    /**
     * O unico campo com sinal do backoffice: negativo e desconto, positivo e
     * acrescimo. O limite de o total nao ficar negativo NAO vive aqui —
     * depende do subtotal e dos portes ja guardados, e essas invariantes sao
     * do OrderService.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'adjustment_price' => ['required', 'numeric', 'min:-9999.99', 'max:9999.99'],
            'adjustment_reason' => ['nullable', 'string', 'max:200'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                // Mesma regra do `price_override_reason` das linhas, um nivel
                // acima: mexer no valor e permitido, mexer sem justificacao
                // nao. Aqui e nao no service para o erro sair por baixo do
                // campo em vez de num toast.
                $cents = Money::fromDecimal((string) $this->input('adjustment_price', '0'));

                if ($cents !== 0 && blank($this->input('adjustment_reason'))) {
                    $validator->errors()->add(
                        'adjustment_reason',
                        'Indica o motivo do ajuste ao total.',
                    );
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'adjustment_price' => 'ajuste',
            'adjustment_reason' => 'motivo do ajuste',
        ];
    }
}
