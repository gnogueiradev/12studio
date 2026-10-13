<?php

namespace App\Etsy;

use App\Mcp\McpFormat;

/**
 * O que a Etsy devolve, reduzido ao que interessa a quem le.
 *
 * Um anuncio da Etsy traz dezenas de campos, a maioria ruido. Aqui fica o que
 * serve o backoffice e o MCP, com os nomes da Etsy (para se poder conferir
 * com a documentacao dela) e o dinheiro ja em centimos.
 *
 * `$trusted` distingue o texto da nossa loja do texto dos outros: o titulo de
 * um anuncio de outro vendedor e tao pouco de confianca como a nota de um
 * cliente, e vai embrulhado da mesma maneira.
 */
class EtsyPresenter
{
    /**
     * A Etsy da o dinheiro como {amount, divisor, currency_code}.
     */
    public static function cents(mixed $money): ?int
    {
        if (! is_array($money) || ! is_numeric($money['amount'] ?? null)) {
            return null;
        }

        $divisor = is_numeric($money['divisor'] ?? null) && (int) $money['divisor'] > 0 ? (int) $money['divisor'] : 100;

        return (int) round(((int) $money['amount']) * 100 / $divisor);
    }

    /**
     * Um endereco que a Etsy mandou, so se for mesmo um endereco https da
     * Etsy. O que vem da API e tratado como vindo de fora: isto acaba num
     * `href` do backoffice, e um `javascript:` ali corria com a sessao do
     * administrador. Tudo o resto vira null — o ecra fica sem ligacao, que e
     * o pior que pode acontecer.
     */
    public static function link(mixed $url): ?string
    {
        if (! is_string($url) || ! str_starts_with($url, 'https://') || strlen($url) > 500) {
            return null;
        }

        // Barras invertidas, espacos e caracteres de controlo nao tem lugar
        // num endereco — e um browser le "evil.example\.etsy.com" como
        // evil.example, onde o PHP ve um servidor acabado em .etsy.com.
        if (preg_match('/[\\\\\s\x00-\x1f\x7f]/', $url) === 1) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && ($host === 'etsy.com' || str_ends_with($host, '.etsy.com')) ? $url : null;
    }

    public static function currency(mixed $money): ?string
    {
        return is_array($money) && is_string($money['currency_code'] ?? null) ? $money['currency_code'] : null;
    }

    /**
     * @return array{amount: string, currency: string|null, cents: int}|null
     */
    public static function money(mixed $money): ?array
    {
        $cents = self::cents($money);

        if ($cents === null) {
            return null;
        }

        return [
            'amount' => number_format($cents / 100, 2, ',', ''),
            'currency' => self::currency($money),
            'cents' => $cents,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $listing
     * @return array<string, mixed>
     */
    public static function listingSummary(array $listing, bool $trusted = true): array
    {
        return [
            'listing_id' => $listing['listing_id'] ?? null,
            'shop_id' => $listing['shop_id'] ?? null,
            'title' => self::text($listing['title'] ?? null, $trusted),
            'state' => $listing['state'] ?? null,
            'url' => self::link($listing['url'] ?? null),
            'price' => self::money($listing['price'] ?? null),
            'quantity' => $listing['quantity'] ?? null,
            // A referencia tambem e texto livre de quem escreveu o anuncio.
            'skus' => self::texts($listing['skus'] ?? null, $trusted),
            'has_variations' => $listing['has_variations'] ?? null,
            'taxonomy_id' => $listing['taxonomy_id'] ?? null,
            'tags' => self::texts($listing['tags'] ?? null, $trusted),
            'views' => $listing['views'] ?? null,
            'num_favorers' => $listing['num_favorers'] ?? null,
            'image' => self::imageUrl($listing),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $listing
     * @return array<string, mixed>
     */
    public static function listingDetail(array $listing, bool $trusted = true): array
    {
        return [
            ...self::listingSummary($listing, $trusted),
            'description' => self::text($listing['description'] ?? null, $trusted),
            'materials' => self::texts($listing['materials'] ?? null, $trusted),
            'who_made' => $listing['who_made'] ?? null,
            'when_made' => $listing['when_made'] ?? null,
            'shipping_profile_id' => $listing['shipping_profile_id'] ?? null,
            'return_policy_id' => $listing['return_policy_id'] ?? null,
            'shop_section_id' => $listing['shop_section_id'] ?? null,
            'images' => array_map(fn (array $image): array => [
                'listing_image_id' => $image['listing_image_id'] ?? null,
                'rank' => $image['rank'] ?? null,
                'url' => $image['url_570xN'] ?? $image['url_fullxfull'] ?? null,
                'alt_text' => self::text($image['alt_text'] ?? null, $trusted),
            ], self::rows($listing['images'] ?? null)),
            'videos' => array_map(fn (array $video): array => [
                'video_id' => $video['video_id'] ?? null,
                'url' => $video['video_url'] ?? null,
                'state' => $video['video_state'] ?? null,
            ], self::rows($listing['videos'] ?? null)),
            'inventory' => array_map(fn (array $product): array => [
                'product_id' => $product['product_id'] ?? null,
                'sku' => self::text($product['sku'] ?? null, $trusted),
                'properties' => array_map(fn (array $property): array => [
                    'property_id' => $property['property_id'] ?? null,
                    'name' => self::text($property['property_name'] ?? null, $trusted),
                    'values' => self::texts($property['values'] ?? null, $trusted),
                ], self::rows($product['property_values'] ?? null)),
                'offerings' => array_map(fn (array $offering): array => [
                    'offering_id' => $offering['offering_id'] ?? null,
                    'price' => self::money($offering['price'] ?? null),
                    'quantity' => $offering['quantity'] ?? null,
                    'is_enabled' => $offering['is_enabled'] ?? null,
                ], self::rows($product['offerings'] ?? null)),
            ], array_values(array_filter(
                self::rows($listing['inventory']['products'] ?? null),
                fn (array $product): bool => ! ($product['is_deleted'] ?? false),
            ))),
        ];
    }

    /**
     * A miniatura de um anuncio que veio com `includes=Images`.
     *
     * @param  array<array-key, mixed>  $listing
     */
    public static function imageUrl(array $listing): ?string
    {
        $first = self::rows($listing['images'] ?? null)[0] ?? null;
        $url = $first['url_170x135'] ?? $first['url_570xN'] ?? $first['url_fullxfull'] ?? null;

        return is_string($url) ? $url : null;
    }

    /**
     * So as entradas que sao mesmo linhas: a Etsy pode mandar null onde se
     * esperava uma lista, e um `includes` que nao veio simplesmente nao esta.
     *
     * @return array<int, array<array-key, mixed>>
     */
    public static function rows(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    private static function text(mixed $value, bool $trusted): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $trusted ? $value : McpFormat::untrusted($value);
    }

    /**
     * @return array<int, string>
     */
    private static function texts(mixed $values, bool $trusted): array
    {
        $texts = [];

        foreach (is_array($values) ? $values : [] as $value) {
            $text = self::text($value, $trusted);

            if ($text !== null) {
                $texts[] = $text;
            }
        }

        return $texts;
    }
}
