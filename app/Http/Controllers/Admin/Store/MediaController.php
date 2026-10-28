<?php

namespace App\Http\Controllers\Admin\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Store\StoreMediaRequest;
use App\Models\Media;
use App\Services\MediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A biblioteca de imagens dos blocos: a pagina /admin/montra/media e o JSON
 * que o seletor de imagem do editor le.
 */
class MediaController extends Controller
{
    public function __construct(
        private MediaService $media,
    ) {}

    public function index(): Response
    {
        return Inertia::render('admin/montra/media', [
            'media' => Media::query()->latest('id')->paginate(48)->through(fn (Media $media): array => $this->row($media)),
        ]);
    }

    /** O seletor do editor: as mais recentes primeiro, com pesquisa pelo nome. */
    public function list(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('q', ''));

        $page = Media::query()
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('original_name', 'like', "%{$search}%")
                ->orWhere('alt', 'like', "%{$search}%")))
            ->latest('id')
            ->paginate(40);

        return response()->json([
            'data' => collect($page->items())->map(fn (Media $media): array => $this->row($media))->all(),
            'next' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
        ]);
    }

    public function store(StoreMediaRequest $request): JsonResponse|RedirectResponse
    {
        $created = array_map(
            fn ($file): array => $this->row($this->media->store($file, $request->validated('alt'))),
            $request->file('files'),
        );

        if ($request->expectsJson()) {
            return response()->json(['data' => $created], 201);
        }

        $this->toast(count($created) === 1 ? 'Imagem carregada.' : count($created).' imagens carregadas.');

        return back();
    }

    public function update(Request $request, Media $media): RedirectResponse|JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        $data = $request->validate(['alt' => ['nullable', 'string', 'max:160']], [], ['alt' => 'texto alternativo']);
        $media->update(['alt' => $data['alt'] ?? null]);

        if ($request->expectsJson()) {
            return response()->json(['data' => $this->row($media)]);
        }

        $this->toast('Texto alternativo guardado.');

        return back();
    }

    public function destroy(Request $request, Media $media): RedirectResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        try {
            $this->media->delete($media);
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        $this->toast('Imagem apagada.');

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Media $media): array
    {
        return [
            'id' => $media->id,
            'name' => $media->original_name,
            'alt' => $media->alt,
            'width' => $media->width,
            'height' => $media->height,
            'size' => $media->size,
            'image' => $media->responsive($media->alt ?? ''),
        ];
    }
}
