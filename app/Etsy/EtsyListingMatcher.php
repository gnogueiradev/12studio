<?php

namespace App\Etsy;

use App\Models\EtsyListing;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * A que produto pertence este anuncio da Etsy?
 *
 * Uma sugestao e nunca uma decisao: quem liga e o dono, com um clique. Por
 * isso a regra e conservadora — uma sugestao errada aceite sem olhar punha o
 * stock de um produto no anuncio de outro.
 *
 *   1. A referencia. Um SKU em comum e identidade; ganha quem tiver mais.
 *   2. O nome, so quando nao ha referencias em comum: o nome do produto
 *      dentro do titulo do anuncio, ou os dois muito parecidos.
 */
class EtsyListingMatcher
{
    /** Parecenca minima (0-100) entre nome e titulo para sugerir pelo nome. */
    private const NAME_SIMILARITY = 70.0;

    /** Um nome mais curto do que isto cabe dentro de qualquer titulo. */
    private const NAME_MIN_LENGTH = 4;

    /**
     * @param  array<int, array<array-key, mixed>>  $listings  Anuncios como a Etsy os devolve.
     * @return array<int, Product> Por `listing_id`; os que nao tem sugestao nao aparecem.
     */
    public function suggest(array $listings): array
    {
        $candidates = $this->candidates();
        $suggestions = [];

        foreach ($listings as $listing) {
            $id = $listing['listing_id'] ?? null;

            if (! is_int($id)) {
                continue;
            }

            $product = $this->bySku($listing, $candidates) ?? $this->byName($listing, $candidates);

            if ($product !== null) {
                $suggestions[$id] = $product;
            }
        }

        return $suggestions;
    }

    /**
     * Os produtos que ainda se podem ligar: os que nao estao apagados (o
     * scope trata disso) nem ja ligados a um anuncio.
     *
     * @return Collection<int, Product>
     */
    private function candidates(): Collection
    {
        return Product::query()
            ->whereNotIn('id', EtsyListing::query()->whereNotNull('etsy_listing_id')->select('product_id'))
            ->with('variants:id,product_id,sku')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<array-key, mixed>  $listing
     * @param  Collection<int, Product>  $candidates
     */
    private function bySku(array $listing, Collection $candidates): ?Product
    {
        $skus = array_map(
            fn (string $sku): string => Str::lower(trim($sku)),
            array_filter((array) ($listing['skus'] ?? []), fn (mixed $sku): bool => is_string($sku) && trim($sku) !== ''),
        );

        if ($skus === []) {
            return null;
        }

        $best = null;
        $bestOverlap = 0;

        foreach ($candidates as $product) {
            $overlap = $product->variants
                ->filter(fn ($variant): bool => in_array(Str::lower($variant->sku), $skus, true))
                ->count();

            if ($overlap > $bestOverlap) {
                $best = $product;
                $bestOverlap = $overlap;
            }
        }

        return $best;
    }

    /**
     * @param  array<array-key, mixed>  $listing
     * @param  Collection<int, Product>  $candidates
     */
    private function byName(array $listing, Collection $candidates): ?Product
    {
        $title = $this->normalize((string) ($listing['title'] ?? ''));

        if ($title === '') {
            return null;
        }

        $best = null;
        $bestScore = 0.0;

        foreach ($candidates as $product) {
            $name = $this->normalize($product->name);

            if (mb_strlen($name) < self::NAME_MIN_LENGTH) {
                continue;
            }

            // O nome inteiro dentro do titulo vale mais do que qualquer
            // parecenca, e entre dois que la caibam ganha o mais comprido.
            if (str_contains($title, $name)) {
                $score = 100.0 + mb_strlen($name);
            } else {
                similar_text($title, $name, $score);

                if ($score < self::NAME_SIMILARITY) {
                    continue;
                }
            }

            if ($score > $bestScore) {
                $best = $product;
                $bestScore = $score;
            }
        }

        return $best;
    }

    private function normalize(string $text): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($text))));
    }
}
