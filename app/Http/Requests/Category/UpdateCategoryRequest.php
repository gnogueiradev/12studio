<?php

namespace App\Http\Requests\Category;

class UpdateCategoryRequest extends StoreCategoryRequest
{
    // Mesmas regras e autorizacao do store — o slug e derivado do nome no
    // CategoryService, nunca aceite do request.
}
