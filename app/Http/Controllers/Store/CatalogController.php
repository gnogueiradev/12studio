<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\PageBuilder\Pages;
use App\Store\CatalogQuery;
use App\Store\ProductCard;
use App\Store\Seo;
use App\Store\StorePage;
use App\Support\LocalizedUrl;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * O catalogo (/produtos) e as categorias (/categoria/{slug}): o mesmo template
 * de blocos, com os produtos filtrados como prop de topo `products` — e assim
 * que o "carregar mais" do Inertia junta as paginas seguintes.
 */
class CatalogController extends Controller
{
    public function __construct(
        private Pages $pages,
        private StorePage $store,
    ) {}

    public function index(Request $request): Response
    {
        return $this->render($request, null);
    }

    public function category(Request $request, string $slug): Response
    {
        // Visivel ou escondida (so por link direto); arquivada nao existe.
        $category = Category::query()
            ->where('slug', $slug)
            ->whereIn('status', ['visible', 'hidden'])
            ->firstOrFail();

        return $this->render($request, $category);
    }

    private function render(Request $request, ?Category $category): Response
    {
        $catalog = CatalogQuery::fromRequest($request, $category);
        $locale = app()->getLocale();
        $page = $this->pages->system('catalog');

        $paginator = $catalog->query()
            ->paginate(CatalogQuery::PER_PAGE)
            ->withQueryString()
            ->through(fn (Product $product): array => ProductCard::make($product, $locale));

        $path = $category === null ? '/produtos' : '/categoria/'.$category->slug;
        $title = $category->name ?? (string) (((array) $page->seo)[$locale]['title'] ?? trans('store.nav.catalog'));

        $breadcrumb = [['label' => trans('store.nav.catalog'), 'href' => LocalizedUrl::path('/produtos')]];
        if ($category !== null) {
            $breadcrumb[] = ['label' => $category->name, 'href' => null];
        }

        return $this->store->render(
            $page,
            $request,
            props: [
                'title' => $title,
                'products' => Inertia::scroll($paginator),
                'catalog' => [
                    'path' => LocalizedUrl::path($path),
                    'category' => $category?->slug,
                    'filters' => $catalog->filters(),
                    'filtered' => $catalog->isFiltered(),
                    'facets' => $catalog->facets(),
                    'total' => $paginator->total(),
                ],
            ],
            subject: ['category' => $category],
            seo: Seo::make(
                path: $path,
                title: $title,
                description: $category?->description,
                jsonLd: $category === null ? [] : [Seo::breadcrumb($breadcrumb)],
                // Uma pesquisa ou combinacao de filtros nao e uma pagina para
                // indexar: o canonical aponta para a listagem limpa.
                index: ! $catalog->isFiltered(),
            ),
        );
    }
}
