<?php

namespace App\Http\Requests\Shop;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Os dados que o cliente muda na conta: as regras do /finalizar para nome,
 * telefone, NIF e morada — e sem email: é o que identifica a conta e o link de
 * entrada vai para ele, por isso não se muda aqui. O país não se escolhe: a
 * loja só envia para Portugal.
 */
class UpdateCustomerDetailsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Um NIF escrito com espaços ("123 456 789") é o mesmo NIF. */
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
            'phone' => ['required', 'string', 'max:30', 'regex:/^\+?[0-9\s\-()]{6,}$/'],
            'nif' => ['nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (! StartCheckoutRequest::validNif((string) $value)) {
                    $fail(trans('store.checkout.errors.nif'));
                }
            }],
            'line1' => ['required', 'string', 'max:120'],
            'line2' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['required', 'string', 'regex:/^\d{4}-\d{3}$/'],
            'city' => ['required', 'string', 'max:80'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => trans('store.checkout.errors.phone'),
            'postal_code.regex' => trans('store.checkout.errors.postal_code'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => trans('store.attributes.name'),
            'phone' => trans('store.attributes.mobile'),
            'nif' => trans('store.attributes.nif'),
            'line1' => trans('store.attributes.address'),
            'line2' => trans('store.attributes.address_more'),
            'postal_code' => trans('store.attributes.postal_code'),
            'city' => trans('store.attributes.city'),
        ];
    }

    /**
     * @return array{name: string, phone: string, nif: string|null}
     */
    public function customer(): array
    {
        return [
            'name' => (string) $this->validated('name'),
            'phone' => (string) $this->validated('phone'),
            'nif' => $this->filled('nif') ? (string) $this->validated('nif') : null,
        ];
    }

    /**
     * Na forma que o CustomerDirectory guarda (a mesma da morada do checkout).
     *
     * @return array{name: string, line1: string, line2: string|null, postalCode: string, city: string, country: string, phone: string}
     */
    public function address(): array
    {
        return [
            'name' => (string) $this->validated('name'),
            'line1' => (string) $this->validated('line1'),
            'line2' => $this->filled('line2') ? (string) $this->validated('line2') : null,
            'postalCode' => (string) $this->validated('postal_code'),
            'city' => (string) $this->validated('city'),
            'country' => 'PT',
            'phone' => (string) $this->validated('phone'),
        ];
    }
}
