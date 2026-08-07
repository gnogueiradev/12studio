<?php

namespace App\Http\Requests\Product;

class UpdateProductRequest extends StoreProductRequest
{
    // Mesmas regras e autorizacao do store — o slug e derivado do nome no
    // ProductService, nunca aceite do request.
}
