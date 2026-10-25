<?php

namespace App\Http\Controllers\Admin\Store;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Store\CatalogController;
use App\Http\Controllers\Store\ProductController;
use App\Http\Middleware\SetStoreLocale;
use App\Http\Middleware\ShareStorefront;
use App\Models\Page;
use App\PageBuilder\Pages;
use App\Store\PreviewProduct;
use App\Store\StorePage;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * A pagina que o editor mostra no iframe: a montra a serio (o mesmo layout e
 * os mesmos controladores), mas sempre com o rascunho. Enquanto se edita, o
 * editor manda-lhe os blocos ja renderizados por postMessage e ela troca-os
 * sem recarregar (store/page.tsx).
 *
 * E a unica pagina que se deixa emoldurar — e so pelo proprio site (ver
 * SecurityHeaders). O idioma vem da query (?locale=en), porque o endereco e
 * o do backoffice e nao tem prefixo.
 */
class PreviewController extends Controller
{
    public function __invoke(Request $request, Page $page, Pages $pages, StorePage $store): Response
    {
        $locale = (string) $request->query('locale', (string) config('locales.default'));

        if (! array_key_exists($locale, (array) config('locales.available'))) {
            $locale = (string) config('locales.default');
        }

        app(SetStoreLocale::class)->apply($locale);
        app(ShareStorefront::class)->share();

        $request->attributes->set(StorePage::PREVIEW, true);

        return match ($page->template()) {
            'catalog' => app(CatalogController::class)->index($request),
            'product' => $this->product($request, $page, $pages, $store),
            default => $store->render($page, $request),
        };
    }

    private function product(Request $request, Page $page, Pages $pages, StorePage $store): Response
    {
        $product = PreviewProduct::pick();

        // Sem produtos nao ha o que mostrar no template: a pagina sai com os
        // blocos que nao precisam de um (relacionados vazios, por exemplo).
        if ($product === null) {
            return $store->render($page, $request);
        }

        return app(ProductController::class)($request, $pages, $store, $product->slug);
    }
}
