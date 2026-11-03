<?php

namespace App\PageBuilder;

use App\Models\Media;
use App\Models\Product;
use App\Support\LocalizedUrl;
use Illuminate\Http\Request;

/**
 * O que os blocos precisam de saber para se resolverem: o idioma, as imagens
 * da biblioteca (carregadas de uma vez para a pagina inteira), o produto ou a
 * categoria quando a pagina e um template, e o pedido (filtros do catalogo).
 */
class RenderContext
{
    /** @var array<int, Media> */
    private array $media = [];

    /**
     * @param  array<string, mixed>  $subject  O produto ou a categoria de um template.
     */
    public function __construct(
        public readonly string $locale,
        public readonly string $defaultLocale,
        public readonly ?Request $request = null,
        public readonly ?Product $product = null,
        public readonly array $subject = [],
    ) {}

    /**
     * @param  array<int, int>  $ids
     */
    public function preloadMedia(array $ids): void
    {
        $missing = array_diff(array_unique($ids), array_keys($this->media));

        if ($missing === []) {
            return;
        }

        foreach (Media::query()->whereKey($missing)->get() as $media) {
            $this->media[$media->id] = $media;
        }
    }

    /**
     * @return array{src: string, srcset: string|null, width: int|null, height: int|null, alt: string}|null
     */
    public function image(mixed $id, string $alt = ''): ?array
    {
        if (! is_int($id)) {
            return null;
        }

        $this->preloadMedia([$id]);

        return isset($this->media[$id]) ? $this->media[$id]->responsive($alt) : null;
    }

    /** Um destino interno ganha o prefixo do idioma; os outros passam. */
    public function url(string $href): string
    {
        return str_starts_with($href, '/') ? LocalizedUrl::path($href, $this->locale) : $href;
    }

    public function translate(string $key): string
    {
        return (string) trans("store.$key", [], $this->locale);
    }
}
