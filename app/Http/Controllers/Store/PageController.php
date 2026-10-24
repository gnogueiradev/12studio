<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\PageBuilder\Pages;
use App\Store\StorePage;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * As paginas de sistema da montra (inicio, sobre, contacto, legais...). A
 * rota diz qual com `->defaults('page', ...)` — ver routes/store.php.
 */
class PageController extends Controller
{
    public function __invoke(Request $request, Pages $pages, StorePage $store, string $page): Response
    {
        return $store->render($pages->system($page), $request);
    }
}
