<?php

namespace App\PageBuilder;

/**
 * Do endereco colado no editor ao endereco de embed. So o YouTube (em modo
 * sem cookies) e o Vimeo: sao os dois hosts que o CSP deixa emoldurar.
 * Aceita tambem o proprio endereco de embed, para regravar o que ja foi
 * gravado sem o estragar.
 */
final class VideoEmbed
{
    private const YOUTUBE_ID = '[A-Za-z0-9_-]{11}';

    public static function fromUrl(string $url): ?string
    {
        $url = trim($url);

        $youtube = [
            '#^https://(?:www\.|m\.)?youtube\.com/watch\?(?:[^\s\#]*&)?v=('.self::YOUTUBE_ID.')(?:[&\#][^\s]*)?$#i',
            '#^https://youtu\.be/('.self::YOUTUBE_ID.')(?:[/?\#][^\s]*)?$#i',
            '#^https://(?:www\.|m\.)?youtube\.com/shorts/('.self::YOUTUBE_ID.')(?:[/?\#][^\s]*)?$#i',
            '#^https://www\.youtube-nocookie\.com/embed/('.self::YOUTUBE_ID.')$#i',
        ];

        foreach ($youtube as $pattern) {
            if (preg_match($pattern, $url, $match) === 1) {
                return 'https://www.youtube-nocookie.com/embed/'.$match[1];
            }
        }

        if (preg_match('#^https://(?:www\.)?vimeo\.com/([0-9]{1,12})(?:[/?\#][^\s]*)?$#i', $url, $match) === 1
            || preg_match('#^https://player\.vimeo\.com/video/([0-9]{1,12})$#i', $url, $match) === 1) {
            return 'https://player.vimeo.com/video/'.$match[1];
        }

        return null;
    }
}
