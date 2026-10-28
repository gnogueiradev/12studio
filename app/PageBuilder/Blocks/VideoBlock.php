<?php

namespace App\PageBuilder\Blocks;

use App\PageBuilder\Block;
use App\PageBuilder\Field;
use App\PageBuilder\StyleProp;
use App\PageBuilder\VideoEmbed;

class VideoBlock extends Block
{
    public function type(): string
    {
        return 'video';
    }

    public function label(): string
    {
        return 'Vídeo';
    }

    public function description(): string
    {
        return 'Um vídeo do YouTube ou do Vimeo.';
    }

    public function fields(): array
    {
        return [
            Field::text('url', 'Endereço do vídeo')->required()->max(300)->help('Cola o link do YouTube ou do Vimeo.'),
        ];
    }

    public function styleFields(): array
    {
        return [
            StyleProp::enum('ratio', 'Proporção', ['16:9' => '16:9', '4:3' => '4:3', '1:1' => '1:1', '9:16' => '9:16'], '16:9'),
        ];
    }

    public function hasShell(): bool
    {
        return false;
    }

    /** Guarda o endereco de embed; qualquer outro host e recusado. */
    public function finalize(array $clean, string $path, array &$errors): array
    {
        $url = (string) ($clean['settings']['url'] ?? '');

        if ($url === '') {
            return $clean;
        }

        $embed = VideoEmbed::fromUrl($url);

        if ($embed === null) {
            $errors["$path.settings.url"] ??= 'Só vídeos do YouTube ou do Vimeo.';
        }

        $clean['settings']['url'] = $embed ?? '';

        return $clean;
    }
}
