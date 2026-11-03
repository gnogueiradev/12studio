<?php

namespace App\Store;

use App\Models\Page;
use App\Models\Product;
use App\PageBuilder\PageRenderer;
use App\PageBuilder\RenderContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Renderiza uma pagina de blocos da montra.
 *
 * O publico ve o que esta publicado (e, numa landing, dentro das datas). O
 * admin ve tambem o que ainda nao esta: o rascunho, com uma faixa a dize-lo —
 * e assim que confere uma pagina antes de a publicar.
 */
class StorePage
{
    /**
     * Atributo do pedido que o PreviewController poe: a pagina e a do iframe
     * do editor — mostra sempre o rascunho, sem faixa, e o React fica a
     * escutar os blocos que o editor lhe manda.
     */
    public const PREVIEW = 'pagebuilder.preview';

    public function __construct(
        private PageRenderer $renderer,
    ) {}

    /**
     * @param  array<string, mixed>  $props  Props extra (o produto, os filtros do catalogo).
     * @param  array<string, mixed>  $subject
     * @param  array<string, mixed>|null  $seo  As meta tags (Seo::make); por omissao, as da pagina.
     */
    public function render(Page $page, Request $request, array $props = [], ?Product $product = null, array $subject = [], ?array $seo = null): Response
    {
        $admin = (bool) $request->user()?->isAdmin();
        $live = $page->isLive();
        $preview = $admin && $request->attributes->get(self::PREVIEW) === true;

        if (! $live && ! $admin) {
            abort(404);
        }

        // O admin num pagina ja publicada ve o publicado (o que o cliente ve);
        // o rascunho confere-se pela pre-visualizacao do editor.
        $draft = ! $live;
        $blocks = $draft || $preview ? $page->draft_blocks : (array) $page->published_blocks;

        $locale = app()->getLocale();
        $default = (string) config('locales.default');
        $context = new RenderContext($locale, $default, $request, $product, $subject);

        $title = $props['title'] ?? $this->title($page, $locale, $default);
        unset($props['title']);

        $seo ??= Seo::make(
            path: $page->path() ?? '/',
            title: $title,
            description: $this->description($page, $locale, $default),
            jsonLd: $page->key === 'home' ? [Seo::organization()] : [],
            index: ! $draft && ! $preview,
        );

        return Inertia::render('store/page', [
            'page' => [
                'key' => $page->key ?? $page->slug,
                'title' => $title,
                'draft' => $draft && ! $preview,
                'preview' => $preview,
                'blocks' => $this->renderer->render($blocks, $context),
            ],
            ...$props,
        ])->withViewData(['seo' => $seo]);
    }

    private function description(Page $page, string $locale, string $default): ?string
    {
        $seo = (array) $page->seo;

        return $seo[$locale]['description'] ?? $seo[$default]['description'] ?? null;
    }

    private function title(Page $page, string $locale, string $default): string
    {
        $seo = (array) $page->seo;

        return (string) ($seo[$locale]['title'] ?? $seo[$default]['title'] ?? ($page->isLanding() ? $page->name : ''));
    }
}
