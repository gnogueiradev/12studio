<?php

namespace App\PageBuilder;

use App\Models\Page;
use Illuminate\Database\QueryException;

/**
 * Onde se encontram as paginas da montra. As de sistema nascem aqui, na
 * primeira vez que alguem as pede, com o conteudo de origem (DefaultPages) —
 * nao ha seeder para correr no deploy, e uma loja nova arranca completa.
 */
class Pages
{
    public function __construct(
        private BlockSanitizer $sanitizer,
    ) {}

    public function system(string $key): Page
    {
        if (! array_key_exists($key, Page::SYSTEM)) {
            throw new \InvalidArgumentException("Pagina de sistema desconhecida: {$key}");
        }

        $page = Page::query()->where('key', $key)->first();

        if ($page !== null) {
            return $page;
        }

        $template = Page::TEMPLATES[$key] ?? 'page';
        $blocks = $this->sanitizer->clean(DefaultPages::blocks($key), $template);
        $draft = in_array($key, DefaultPages::DRAFTS, true);

        try {
            $page = Page::query()->create([
                'type' => 'system',
                'key' => $key,
                'name' => DefaultPages::NAMES[$key],
                'status' => $draft ? 'draft' : 'published',
                'seo' => DefaultPages::seo($key),
                'draft_blocks' => $blocks,
                'published_blocks' => $draft ? null : $blocks,
                'published_at' => $draft ? null : now(),
            ]);

            // A versao de origem fica no historico: "Repor esta versao"
            // devolve a pagina como a loja nasceu.
            if (! $draft) {
                $page->revisions()->create(['blocks' => $blocks, 'seo' => $page->seo, 'created_at' => now()]);
            }

            return $page;
        } catch (QueryException) {
            // Dois pedidos ao mesmo tempo na primeira visita: o outro criou-a.
            return Page::query()->where('key', $key)->firstOrFail();
        }
    }

    public function landing(string $slug): ?Page
    {
        return Page::query()->where('type', 'landing')->where('slug', $slug)->first();
    }
}
