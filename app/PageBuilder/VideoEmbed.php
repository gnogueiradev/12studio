<?php

namespace App\PageBuilder;

/**
 * Do endereco colado no editor ao endereco de embed. So o YouTube (em modo
 * sem cookies) e o Vimeo: sao os dois hosts que o CSP deixa emoldurar.
 * Aceita o que o dono costuma colar (http, sem esquema, m., /live/, links
 * privados do Vimeo) e o proprio endereco de embed, para regravar o que ja
 * foi gravado sem o estragar.
 *
 * O host fica ancorado ao inicio, logo a seguir ao esquema opcional: nada de
 * dominios parecidos nem de "youtube.com@outro.sitio". A saida e sempre
 * reconstruida a partir dos ids capturados.
 */
final class VideoEmbed
{
    private const YOUTUBE_ID = '[A-Za-z0-9_-]{11}';

    private const SCHEME = '(?:https?://)?';

    public static function fromUrl(string $url): ?string
    {
        $url = trim($url);
        $id = self::YOUTUBE_ID;
        $scheme = self::SCHEME;

        $youtube = [
            // watch?v=ID com parametros antes ou depois
            "#^{$scheme}(?:www\\.|m\\.)?youtube\\.com/watch\\?(?:[^\\s\\#]*&)?v=({$id})(?:[&\\#][^\\s]*)?$#i",
            "#^{$scheme}youtu\\.be/({$id})(?:[/?\\#][^\\s]*)?$#i",
            "#^{$scheme}(?:www\\.|m\\.)?youtube\\.com/(?:shorts|live|embed)/({$id})(?:[/?\\#][^\\s]*)?$#i",
            "#^{$scheme}(?:www\\.)?youtube-nocookie\\.com/embed/({$id})(?:[/?\\#][^\\s]*)?$#i",
        ];

        foreach ($youtube as $pattern) {
            if (preg_match($pattern, $url, $match) === 1) {
                return 'https://www.youtube-nocookie.com/embed/'.$match[1];
            }
        }

        // Vimeo privado: vimeo.com/NUM/HASH, ou o embed ja com ?h=HASH.
        if (preg_match("#^{$scheme}(?:www\\.)?vimeo\\.com/([0-9]{1,12})/([A-Za-z0-9]+)(?:[/?\\#][^\\s]*)?$#i", $url, $match) === 1) {
            return 'https://player.vimeo.com/video/'.$match[1].'?h='.$match[2];
        }

        if (preg_match("#^{$scheme}player\\.vimeo\\.com/video/([0-9]{1,12})(?:\\?(?:[^\\s\\#]*&)?h=([A-Za-z0-9]+)(?:&[^\\s\\#]*)?)?(?:\\#[^\\s]*)?$#i", $url, $match) === 1) {
            return 'https://player.vimeo.com/video/'.$match[1].(($match[2] ?? '') !== '' ? '?h='.$match[2] : '');
        }

        if (preg_match("#^{$scheme}(?:www\\.)?vimeo\\.com/([0-9]{1,12})(?:[/?\\#][^\\s]*)?$#i", $url, $match) === 1) {
            return 'https://player.vimeo.com/video/'.$match[1];
        }

        return null;
    }
}
