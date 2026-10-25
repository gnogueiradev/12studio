<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Translation\CategoryTranslationRequest;
use App\Http\Requests\Translation\ProductTranslationRequest;
use App\Models\Category;
use App\Models\Product;
use App\Services\TranslationService;
use Illuminate\Http\RedirectResponse;

/**
 * As traducoes de produtos e categorias editadas no backoffice. Um idioma de
 * cada vez; o portugues edita-se no proprio formulario.
 */
class TranslationController extends Controller
{
    public function __construct(
        private TranslationService $translations,
    ) {}

    public function product(ProductTranslationRequest $request, Product $product, string $locale): RedirectResponse
    {
        $this->translations->setProduct($product, $locale, $request->validated());
        $this->toast('Tradução ('.strtoupper($locale).') guardada.');

        return back();
    }

    public function category(CategoryTranslationRequest $request, Category $category, string $locale): RedirectResponse
    {
        $this->translations->setCategory($category, $locale, $request->validated());
        $this->toast('Tradução ('.strtoupper($locale).') guardada.');

        return back();
    }
}
