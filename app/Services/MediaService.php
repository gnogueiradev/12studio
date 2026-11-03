<?php

namespace App\Services;

use App\Images\ImageVariants;
use App\Jobs\GenerateImageVariants;
use App\Models\Media;
use App\Models\Page;
use App\PageBuilder\PageDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * A biblioteca de imagens dos blocos da montra. Carregar grava no disco
 * `public` e poe as versoes reduzidas na fila (as mesmas das fotografias de
 * produto); apagar recusa uma imagem que alguma pagina ou o tema ainda usam.
 */
class MediaService
{
    public function __construct(
        private ImageVariants $variants,
        private ThemeSettings $theme,
    ) {}

    public function store(UploadedFile $file, ?string $alt = null): Media
    {
        $path = $file->storeAs('media', Str::uuid().'.'.strtolower($file->extension()), 'public');

        if ($path === false) {
            throw new RuntimeException("Nao foi possivel guardar {$file->getClientOriginalName()}.");
        }

        [$width, $height] = @getimagesize($file->getRealPath()) ?: [null, null];

        $media = Media::query()->create([
            'path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 200),
            'alt' => $alt,
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
            'width' => $width,
            'height' => $height,
        ]);

        dispatch(GenerateImageVariants::for($media))->afterCommit();

        return $media;
    }

    /**
     * Onde a imagem esta a ser usada: os nomes das paginas (rascunho ou
     * publicado) e "Tema" se for o logotipo ou o favicon.
     *
     * @return array<int, string>
     */
    public function usage(Media $media): array
    {
        // O JSON dos blocos guarda a imagem como "image":12 — a procura por
        // texto apanha-a sem abrir cada bloco.
        $needle = '"image":'.$media->id;

        $pages = Page::query()
            ->where(fn ($query) => $query
                ->where('draft_blocks', 'like', "%{$needle},%")
                ->orWhere('draft_blocks', 'like', "%{$needle}}%")
                ->orWhere('published_blocks', 'like', "%{$needle},%")
                ->orWhere('published_blocks', 'like', "%{$needle}}%"))
            ->pluck('name')
            ->all();

        // O fundo de um contentor guarda o id por dispositivo
        // ("bg_image":{"desktop":12}): confirma-se na arvore das paginas que
        // o JSON menciona.
        $backgrounds = Page::query()
            ->where(fn ($query) => $query
                ->where('draft_blocks', 'like', '%"bg_image":%')
                ->orWhere('published_blocks', 'like', '%"bg_image":%'))
            ->get()
            ->filter(fn (Page $page): bool => $this->usesBackground($page->draft_blocks, $media->id)
                || $this->usesBackground($page->published_blocks ?? [], $media->id))
            ->pluck('name')
            ->all();

        $pages = array_values(array_unique([...$pages, ...$backgrounds]));

        $theme = $this->theme->values();
        if (in_array($media->id, [$theme['logo'], $theme['favicon']], true)) {
            $pages[] = 'Tema';
        }

        return $pages;
    }

    /**
     * @param  array<int, array<string, mixed>>  $tree
     */
    private function usesBackground(array $tree, int $id): bool
    {
        $used = false;

        PageDocument::walk($tree, function (array $node) use ($id, &$used): void {
            if (($node['type'] ?? null) === 'container' && in_array($id, (array) ($node['style']['bg_image'] ?? []), true)) {
                $used = true;
            }
        });

        return $used;
    }

    public function delete(Media $media): void
    {
        $usage = $this->usage($media);

        if ($usage !== []) {
            throw ValidationException::withMessages([
                'media' => 'Esta imagem está a ser usada em: '.implode(', ', $usage).'. Tira-a de lá primeiro.',
            ]);
        }

        $media->delete();

        Storage::disk('public')->delete($media->path);
        $this->variants->delete('public', $media->variants);
    }
}
