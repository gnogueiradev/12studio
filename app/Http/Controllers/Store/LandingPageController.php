<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\PageBuilder\Pages;
use App\Store\StorePage;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * As landing pages criadas no construtor ('/natal'). Fora das datas, ou por
 * publicar, dao 404 a quem nao e admin.
 */
class LandingPageController extends Controller
{
    public function __invoke(Request $request, Pages $pages, StorePage $store, string $slug): Response
    {
        $page = $pages->landing($slug);

        abort_if($page === null, 404);

        return $store->render($page, $request);
    }
}
