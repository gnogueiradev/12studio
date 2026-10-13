<?php

namespace App\Http\Requests\Etsy;

use App\Http\Requests\AdminFormRequest;
use App\Support\Money;
use Illuminate\Validation\Rule;

/**
 * As escolhas do dono sobre precos e anuncios na Etsy: a margem sobre o preco
 * do site, as taxas com que se fazem as contas, e o que um anuncio novo leva
 * por omissao. Gravam-se ca; nada disto escreve na Etsy.
 *
 * As percentagens e o valor fixo vem como as pessoas os escrevem ("12,5",
 * "0,48"); o settings() devolve-os ja nas unidades em que se guardam.
 */
class SaveEtsyPricingSettingsRequest extends AdminFormRequest
{
    private const DECIMALS = ['markup_percent', 'fee_percent', 'fee_fixed'];

    protected function prepareForValidation(): void
    {
        foreach (self::DECIMALS as $field) {
            $value = $this->input($field);

            if (is_string($value) && trim($value) !== '') {
                $this->merge([$field => str_replace(',', '.', trim($value))]);
            }
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'markup_percent' => ['sometimes', 'required', 'numeric', 'min:0', 'max:500'],
            'fee_percent' => ['sometimes', 'required', 'numeric', 'min:0', 'max:100'],
            'fee_fixed' => ['sometimes', 'required', 'numeric', 'min:0', 'max:99.99'],
            'mto_quantity' => ['sometimes', 'required', 'integer', 'min:1', 'max:'.config('etsy.listing.quantity_max', 999)],
            'stock_buffer' => ['sometimes', 'required', 'integer', 'min:0', 'max:999'],
            'default_taxonomy_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'who_made' => ['sometimes', 'required', Rule::in((array) config('etsy.listing.who_made'))],
            'when_made' => ['sometimes', 'required', Rule::in((array) config('etsy.listing.when_made'))],
        ];
    }

    /**
     * O que foi validado, com os nomes e as unidades do EtsySettings: pontos
     * base e centimos, sempre inteiros.
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        $data = $this->validated();
        $settings = array_intersect_key($data, array_flip(['mto_quantity', 'stock_buffer', 'default_taxonomy_id', 'who_made', 'when_made']));

        foreach (['markup_percent' => 'markup_bp', 'fee_percent' => 'fee_percentage_bp'] as $field => $name) {
            if (array_key_exists($field, $data)) {
                $settings[$name] = (int) round(Money::normalizeDecimal((string) $data[$field]) * 100);
            }
        }

        if (array_key_exists('fee_fixed', $data)) {
            $settings['fee_fixed_cents'] = Money::fromDecimal((string) $data['fee_fixed']);
        }

        return $settings;
    }
}
