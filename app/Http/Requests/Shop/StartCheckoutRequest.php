<?php

namespace App\Http\Requests\Shop;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Os dados de quem compra no /finalizar. O pais nao se escolhe: a loja so
 * envia para Portugal (continente e ilhas). Os precos e o total nao vem
 * daqui — o `expected_total_cents` e so o que o cliente viu, para o servidor
 * recusar cobrar outro.
 */
class StartCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Um NIF escrito com espacos ("123 456 789") e o mesmo NIF.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('nif'))) {
            $this->merge(['nif' => preg_replace('/\s+/', '', $this->input('nif'))]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            // O RFC aceita "ana@localhost"; o Stripe nao, e falhava so no fim
            // com o erro generico. Um dominio com ponto e extensao de letras.
            'email' => ['required', 'string', 'email:rfc', 'max:255', 'regex:/@[^@\s]+\.[A-Za-z]{2,}$/'],
            'phone' => ['required', 'string', 'max:30', 'regex:/^\+?[0-9\s\-()]{6,}$/'],
            'nif' => ['nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (! self::validNif((string) $value)) {
                    $fail(trans('store.checkout.errors.nif'));
                }
            }],
            'line1' => ['required', 'string', 'max:120'],
            'line2' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['required', 'string', 'regex:/^\d{4}-\d{3}$/'],
            'city' => ['required', 'string', 'max:80'],
            'terms' => ['accepted'],
            'marketing_opt_out' => ['boolean'],
            'expected_total_cents' => ['required', 'integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.regex' => trans('validation.email'),
            'phone.regex' => trans('store.checkout.errors.phone'),
            'postal_code.regex' => trans('store.checkout.errors.postal_code'),
            'terms.accepted' => trans('store.checkout.errors.terms'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => trans('store.attributes.name'),
            'email' => trans('store.attributes.email'),
            'phone' => trans('store.attributes.mobile'),
            'nif' => trans('store.attributes.nif'),
            'line1' => trans('store.attributes.address'),
            'line2' => trans('store.attributes.address_more'),
            'postal_code' => trans('store.attributes.postal_code'),
            'city' => trans('store.attributes.city'),
            'terms' => trans('store.attributes.terms'),
        ];
    }

    /**
     * Nove digitos, e o ultimo e o de controlo (modulo 11): cada um dos oito
     * primeiros pesa de 9 a 2; o resto da soma por 11 da o controlo (0 ou 1
     * dao 0, o resto da 11 menos ele).
     */
    public static function validNif(string $nif): bool
    {
        if (preg_match('/^\d{9}$/', $nif) !== 1) {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 8; $i++) {
            $sum += (int) $nif[$i] * (9 - $i);
        }

        $rest = $sum % 11;

        return (int) $nif[8] === ($rest < 2 ? 0 : 11 - $rest);
    }

    /**
     * @return array{name: string, email: string, phone: string, nif: string|null}
     */
    public function customer(): array
    {
        return [
            'name' => (string) $this->validated('name'),
            'email' => (string) $this->validated('email'),
            'phone' => (string) $this->validated('phone'),
            'nif' => $this->filled('nif') ? (string) $this->validated('nif') : null,
        ];
    }

    /**
     * A caixa desmarcada e "nao recusa". Ausente (uma pagina de antes de um
     * deploy, um pedido feito a mao) e `null`: nao se perguntou, e uma
     * encomenda assim nunca da oferta.
     */
    public function marketingOptOut(): ?bool
    {
        return $this->has('marketing_opt_out') ? $this->boolean('marketing_opt_out') : null;
    }

    /**
     * @return array{line1: string, line2: string|null, postal_code: string, city: string}
     */
    public function address(): array
    {
        return [
            'line1' => (string) $this->validated('line1'),
            'line2' => $this->filled('line2') ? (string) $this->validated('line2') : null,
            'postal_code' => (string) $this->validated('postal_code'),
            'city' => (string) $this->validated('city'),
        ];
    }
}
