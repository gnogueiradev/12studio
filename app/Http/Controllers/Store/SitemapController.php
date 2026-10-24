<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Page;
use App\Models\Product;
use App\Services\StoreSettings;
use App\Support\LocalizedUrl;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * O sitemap da montra: paginas publicadas, catalogo, categorias visiveis,
 * produtos a venda e landings no ar — cada endereco em todos os idiomas
 * ligados, com as alternates de cada um. Uma hora em cache; com a montra
 * fechada nao existe.
 */
class SitemapController extends Controller
{
    public const CACHE_KEY = 'store.sitemap';

    public function __invoke(StoreSettings $store): Response
    {
        abort_unless($store->isOpen(), 404);

        $xml = Cache::remember(self::CACHE_KEY, 3600, fn (): string => $this->build($store->locales()));

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    /**
     * @param  array<int, string>  $locales
     */
    private function build(array $locales): string
    {
        $entries = [];

        foreach (Page::query()->where('status', 'published')->get() as $page) {
            if ($page->isLive() && $page->path() !== null) {
                $entries[] = [$page->path(), $page->updated_at];
            }
        }

        $entries[] = ['/produtos', null];

        foreach (Category::query()->where('status', 'visible')->get(['slug', 'updated_at']) as $category) {
            $entries[] = ['/categoria/'.$category->slug, $category->updated_at];
        }

        foreach (Product::query()->visibleInStore()->get(['slug', 'updated_at']) as $product) {
            $entries[] = ['/produtos/'.$product->slug, $product->updated_at];
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">'."\n";

        foreach (collect($entries)->unique(0) as [$path, $updated]) {
            $alternates = '';
            foreach ($locales as $code) {
                $alternates .= sprintf(
                    '<xhtml:link rel="alternate" hreflang="%s" href="%s"/>',
                    e((string) config("locales.available.$code.hreflang", $code)),
                    e(url(LocalizedUrl::path($path, $code))),
                );
            }

            foreach ($locales as $code) {
                $xml .= '<url><loc>'.e(url(LocalizedUrl::path($path, $code))).'</loc>'
                    .($updated instanceof Carbon ? '<lastmod>'.$updated->toAtomString().'</lastmod>' : '')
                    .$alternates."</url>\n";
            }
        }

        return $xml.'</urlset>'."\n";
    }
}
