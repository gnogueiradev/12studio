<?php

namespace App\Http\Controllers\Admin\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Store\LandingPageRequest;
use App\Http\Requests\Store\SavePageDraftRequest;
use App\Models\Category;
use App\Models\Media;
use App\Models\Page;
use App\Models\PageRevision;
use App\Models\Product;
use App\Models\Tag;
use App\PageBuilder\Block;
use App\PageBuilder\BlockRegistry;
use App\PageBuilder\BlockSanitizer;
use App\PageBuilder\DefaultPages;
use App\PageBuilder\Field;
use App\PageBuilder\PageBuilderService;
use App\PageBuilder\PageRenderer;
use App\PageBuilder\Pages;
use App\PageBuilder\RenderContext;
use App\Store\PreviewProduct;
use App\Store\StoreLinks;
use App\Support\LocalizedUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * O construtor de paginas da montra: a lista (/admin/montra/paginas) e o
 * editor de cada uma. Toda a escrita passa pelo PageBuilderService; aqui so
 * se traduz o pedido e a resposta.
 *
 * O autosave e a pre-visualizacao falam JSON (o editor fica na mesma pagina
 * enquanto grava); publicar, repor e o resto sao visitas Inertia normais.
 */
class PageController extends Controller
{
    public function __construct(
        private PageBuilderService $builder,
        private Pages $pages,
    ) {}

    public function index(): Response
    {
        // Garante que as de sistema existem (nascem na primeira vez que se
        // pedem) antes de as listar.
        foreach (array_keys(Page::SYSTEM) as $key) {
            $this->pages->system($key);
        }

        $all = Page::query()->orderBy('id')->get();
        $order = array_flip(array_keys(Page::SYSTEM));

        return Inertia::render('admin/montra/paginas', [
            'system' => $all->where('type', 'system')
                ->sortBy(fn (Page $page): int => $order[$page->key] ?? PHP_INT_MAX)
                ->map(fn (Page $page): array => $this->row($page))
                ->values()
                ->all(),
            'landings' => $all->where('type', 'landing')
                ->sortBy('name')
                ->map(fn (Page $page): array => $this->row($page))
                ->values()
                ->all(),
        ]);
    }

    public function store(LandingPageRequest $request): RedirectResponse
    {
        try {
            $page = $this->builder->createLanding($request->landing());
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors())->withInput();
        }

        $this->toast('Landing page criada. Junta-lhe blocos e publica quando estiver pronta.');

        return to_route('admin.montra.paginas.edit', $page);
    }

    public function edit(Page $page, BlockRegistry $registry): Response
    {
        return Inertia::render('admin/montra/editor', [
            'page' => $this->editable($page),
            'schema' => $registry->schema($page->template()),
            'styles' => [
                'bg' => $this->options(Block::BACKGROUNDS),
                'spacing' => $this->options(Block::SPACINGS),
                'width' => $this->options(Block::WIDTHS),
                'align' => $this->options(Block::ALIGNS),
            ],
            'revisions' => $page->revisions()->with('user:id,name')->get()
                ->map(fn (PageRevision $revision): array => [
                    'id' => $revision->id,
                    'createdAt' => $revision->created_at?->toIso8601String(),
                    'user' => $revision->user?->name,
                    'blocks' => count($revision->blocks),
                ])
                ->all(),
            'locales' => array_keys((array) config('locales.available')),
            'previewUrl' => route('admin.montra.preview', $page, false),
            'publicUrl' => $page->path(),
            'links' => StoreLinks::all(),
            'categories' => Category::query()->whereIn('status', ['visible', 'hidden'])->orderBy('sort_order')->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Category $category): array => ['value' => $category->id, 'label' => $category->name])
                ->all(),
            'tags' => Tag::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Tag $tag): array => ['value' => $tag->id, 'label' => $tag->name])
                ->all(),
            'pickedProducts' => $this->pickedProducts($page),
            'media' => (object) $this->mediaPreviews($page, $registry),
        ]);
    }

    /** Autosave: grava o rascunho e devolve o estado da pagina. */
    public function saveDraft(SavePageDraftRequest $request, Page $page): JsonResponse
    {
        $page = $this->builder->saveDraft($page, (array) $request->input('blocks', []), $request->has('seo') ? (array) $request->input('seo') : null);

        return response()->json(['page' => $this->status($page)]);
    }

    /**
     * Os blocos do editor ja renderizados como a montra os mostra, sem gravar
     * nada. O editor manda-os ao iframe da pre-visualizacao.
     */
    public function render(Request $request, Page $page, BlockSanitizer $sanitizer, PageRenderer $renderer): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        $locale = (string) $request->input('locale', config('locales.default'));
        if (! array_key_exists($locale, (array) config('locales.available'))) {
            $locale = (string) config('locales.default');
        }

        // Os links internos e os textos da montra no idioma da pre-visualizacao.
        $previous = app()->getLocale();
        app()->setLocale($locale);

        try {
            $blocks = $sanitizer->preview((array) $request->input('blocks', []), $page->template());
            $product = $page->template() === 'product' ? PreviewProduct::pick() : null;
            $context = new RenderContext($locale, (string) config('locales.default'), $request, $product);

            return response()->json(['blocks' => $renderer->render($blocks, $context)]);
        } finally {
            app()->setLocale($previous);
        }
    }

    public function publish(Request $request, Page $page): RedirectResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        try {
            $this->builder->publish($page, $request->user());
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        $this->toast('Publicada. O site já mostra esta versão.');

        return back();
    }

    public function unpublish(Request $request, Page $page): RedirectResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        try {
            $this->builder->unpublish($page);
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        $this->toast('A página saiu do ar. O rascunho e o histórico ficam.');

        return back();
    }

    public function discard(Request $request, Page $page): RedirectResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        $this->builder->discard($page);
        $this->toast('Rascunho descartado: voltou ao que está publicado.');

        return back();
    }

    public function duplicate(Request $request, Page $page): RedirectResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        try {
            $copy = $this->builder->duplicate($page);
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        $this->toast('Cópia criada como landing page em rascunho.');

        return to_route('admin.montra.paginas.edit', $copy);
    }

    public function updateSettings(LandingPageRequest $request, Page $page): RedirectResponse
    {
        try {
            $this->builder->updateLanding($page, $request->landing());
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors())->withInput();
        }

        $this->toast('Endereço e datas guardados.');

        return back();
    }

    public function restore(Request $request, Page $page, PageRevision $revision): RedirectResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        try {
            $this->builder->restore($page, $revision);
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        $this->toast('Versão reposta no rascunho. Publica para o site a mostrar.');

        return back();
    }

    public function destroy(Request $request, Page $page): RedirectResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        try {
            $this->builder->delete($page);
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        $this->toast('Landing page apagada.');

        return to_route('admin.montra.paginas.index');
    }

    /** O seletor de produtos dos blocos: pesquisa pelo nome. */
    public function products(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        $search = trim((string) $request->query('q', ''));

        $products = Product::query()
            ->whereIn('status', ['active', 'draft'])
            ->when($search !== '', fn ($query) => $query->whereRaw("name LIKE ? ESCAPE '\\'", ['%'.addcslashes($search, '%_\\').'%']))
            ->with(['images' => fn ($query) => $query->orderByDesc('is_primary')->orderBy('sort_order')])
            ->orderBy('name')
            ->limit(20)
            ->get();

        return response()->json(['data' => $products->map(fn (Product $product): array => $this->productOption($product))->all()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Page $page): array
    {
        return [
            'id' => $page->id,
            'key' => $page->key,
            'type' => $page->type,
            'name' => $page->name,
            'slug' => $page->slug,
            'path' => $page->path(),
            'template' => $page->template(),
            'status' => $page->status,
            'hasChanges' => $page->hasUnpublishedChanges(),
            'live' => $page->isLive(),
            'startsAt' => $page->starts_at?->toIso8601String(),
            'endsAt' => $page->ends_at?->toIso8601String(),
            'publishedAt' => $page->published_at?->toIso8601String(),
            'updatedAt' => $page->updated_at?->toIso8601String(),
            'canUnpublish' => $page->isLanding() || in_array($page->key, DefaultPages::DRAFTS, true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function editable(Page $page): array
    {
        return $this->row($page) + [
            'blocks' => $page->draft_blocks,
            'seo' => (object) ($page->seo ?? []),
            'startsAtInput' => $page->starts_at?->format('Y-m-d\TH:i'),
            'endsAtInput' => $page->ends_at?->format('Y-m-d\TH:i'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function status(Page $page): array
    {
        return [
            'status' => $page->status,
            'hasChanges' => $page->hasUnpublishedChanges(),
            'live' => $page->isLive(),
            'updatedAt' => $page->updated_at?->toIso8601String() ?? Carbon::now()->toIso8601String(),
            // O rascunho limpo (ids novos, campos normalizados) para o editor
            // nao ficar com uma versao diferente da gravada.
            'blocks' => $page->draft_blocks,
        ];
    }

    /**
     * Os produtos ja escolhidos nos blocos (nome e foto para o seletor).
     *
     * @return array<int, array<string, mixed>>
     */
    private function pickedProducts(Page $page): array
    {
        $ids = [];

        foreach ($page->draft_blocks as $block) {
            foreach ((array) ($block['settings'] ?? []) as $value) {
                if (is_array($value) && array_is_list($value) && $value !== [] && is_int($value[0])) {
                    $ids = [...$ids, ...$value];
                }
            }
        }

        if ($ids === []) {
            return [];
        }

        return Product::query()
            ->whereKey(array_unique($ids))
            ->with(['images' => fn ($query) => $query->orderByDesc('is_primary')->orderBy('sort_order')])
            ->get()
            ->map(fn (Product $product): array => $this->productOption($product))
            ->all();
    }

    /**
     * As miniaturas das imagens ja escolhidas nos blocos (id => url), para o
     * inspetor as mostrar sem ir a biblioteca.
     *
     * @return array<int, string>
     */
    private function mediaPreviews(Page $page, BlockRegistry $registry): array
    {
        $ids = [];
        $collect = function (Field $field, mixed $value) use (&$collect, &$ids): void {
            if ($field->type === 'image' && is_int($value)) {
                $ids[] = $value;
            }

            if ($field->type === 'repeater' && is_array($value)) {
                foreach ($value as $item) {
                    foreach ($field->fields as $sub) {
                        $collect($sub, is_array($item) ? ($item[$sub->key] ?? null) : null);
                    }
                }
            }
        };

        foreach ($page->draft_blocks as $block) {
            foreach ($registry->get((string) ($block['type'] ?? ''))?->fields() ?? [] as $field) {
                if ($field->translatable) {
                    foreach ((array) ($block['content'] ?? []) as $content) {
                        $collect($field, is_array($content) ? ($content[$field->key] ?? null) : null);
                    }
                } else {
                    $collect($field, $block['settings'][$field->key] ?? null);
                }
            }
        }

        if ($ids === []) {
            return [];
        }

        return Media::query()->whereKey(array_unique($ids))->get()
            ->mapWithKeys(fn (Media $media): array => [$media->id => $media->responsive()['src']])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function productOption(Product $product): array
    {
        $image = $product->images->first();

        return [
            'id' => $product->id,
            'name' => $product->name,
            'status' => $product->status,
            'image' => $image?->responsive($product->name)['src'] ?? null,
            'href' => LocalizedUrl::path('/produtos/'.$product->slug),
        ];
    }

    /**
     * @param  array<string, string>  $options
     * @return array<int, array{value: string, label: string}>
     */
    private function options(array $options): array
    {
        return collect($options)->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])->values()->all();
    }
}
