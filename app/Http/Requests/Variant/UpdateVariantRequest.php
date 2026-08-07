<?php

namespace App\Http\Requests\Variant;

class UpdateVariantRequest extends StoreVariantRequest
{
    // Mesmas regras do store. A unicidade do SKU ja ignora a propria
    // variante via StoreVariantRequest::variantId().
}
