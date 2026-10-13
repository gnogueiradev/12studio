<?php

namespace App\Http\Requests\Etsy;

use App\Http\Requests\AdminFormRequest;

/**
 * Ligar um produto a um anuncio que ja existe na Etsy. Serve o backoffice e,
 * pelo FormRequestRunner, a ferramenta etsy_listing_link.
 */
class LinkEtsyListingRequest extends AdminFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'listing_id' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'listing_id.required' => 'Indica o número do anúncio na Etsy.',
            'listing_id.integer' => 'O número do anúncio na Etsy é só algarismos.',
        ];
    }
}
