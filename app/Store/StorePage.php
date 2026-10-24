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
    public function __construct(
        private PageRenderer $renderer,
    ) {}

    /**
     * @param  array<string, mixed>  $props  Props extra (o produto, os filtros do catalogo).
     * @param  array<string, mixed>  $subject
     */
    public function render(Page $page, Request $request, array $props = [], ?Product $product = null, array $subject = []): Response
    {
        $admin = (bool) $request->user()?->isAdmin();
        $live = $page->isLive();

        if (! $live && ! $admin) {
            abort(404);
        }

        // O admin num pagina ja publicada ve o publicado (o que o cliente ve);
        // o rascunho confere-se pela pre-visualizacao do editor.
        $draft = ! $live;
        $blocks = $draft ? $page->draft_blocks : (array) $page->published_blocks;

        $locale = app()->getLocale();
        $default = (string) config('locales.default');
        $context = new RenderContext($locale, $default, $request, $product, $subject);

        return Inertia::render('store/page', [
            'page' => [
                'key' => $page->key ?? $page->slug,
                'title' => $this->title($page, $locale, $default),
                'draft' => $draft,
                'blocks' => $this->renderer->render($blocks, $context),
            ],
            ...$props,
        ]);
    }

    private function title(Page $page, string $locale, string $default): string
    {
        $seo = (array) $page->seo;

        return (string) ($seo[$locale]['title'] ?? $seo[$default]['title'] ?? ($page->isLanding() ? $page->name : ''));
    }
}
