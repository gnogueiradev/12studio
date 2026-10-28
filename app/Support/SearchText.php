<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Texto de pesquisa normalizado: minusculas, sem acentos nem HTML. O LIKE do
 * SQLite so ignora maiusculas em ASCII, por isso "Peça" e "peca" so se
 * encontram se os dois lados passarem por aqui.
 */
final class SearchText
{
    public static function normalize(?string ...$parts): string
    {
        $text = implode(' ', array_filter($parts, fn (?string $part): bool => $part !== null && $part !== ''));
        $text = html_entity_decode(strip_tags(str_replace('<', ' <', $text)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/', ' ', Str::lower(Str::ascii($text))));
    }

    /**
     * As palavras de uma pesquisa, normalizadas e sem as muito curtas.
     *
     * @return array<int, string>
     */
    public static function terms(string $query): array
    {
        return array_values(array_unique(array_filter(
            explode(' ', self::normalize($query)),
            fn (string $term): bool => mb_strlen($term) >= 2,
        )));
    }

    /** Escapa os curingas do LIKE para uma pesquisa por "50%" nao apanhar tudo. */
    public static function like(string $term): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
    }
}
