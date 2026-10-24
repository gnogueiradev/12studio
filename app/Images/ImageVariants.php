<?php

namespace App\Images;

use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\ImageManager;
use RuntimeException;

/**
 * As versoes reduzidas de uma imagem: WebP em varias larguras (para o srcset
 * da montra) e um JPEG de 1200 px para o Open Graph, que nem todas as redes
 * leem em WebP.
 *
 * Reencodar tem um efeito que se quer: os metadados EXIF saem todos,
 * localizacao GPS incluida. A orientacao da camara aplica-se antes, por isso a
 * foto tirada ao alto fica ao alto.
 */
class ImageVariants
{
    /** Larguras do srcset. Nunca se amplia: um original estreito fica-se pelas que cabem. */
    public const WIDTHS = [320, 640, 1024, 1600];

    public const OG_WIDTH = 1200;

    private const WEBP_QUALITY = 80;

    private const JPEG_QUALITY = 82;

    /**
     * @return array{width: int, height: int, variants: array{webp: array<int, string>, og: string}}
     */
    public function generate(string $disk, string $path): array
    {
        $storage = Storage::disk($disk);

        if (! $storage->exists($path)) {
            throw new RuntimeException("A imagem {$path} nao existe no disco {$disk}.");
        }

        // Descodificado uma vez; cada versao trabalha sobre uma copia.
        $source = $this->manager()->read($storage->get($path));
        $width = $source->width();
        $height = $source->height();

        $webp = [];
        foreach ($this->widthsFor($width) as $target) {
            $variantPath = $this->variantPath($path, (string) $target, 'webp');
            $image = (clone $source)->scaleDown(width: $target);
            $storage->put($variantPath, (string) $image->toWebp(self::WEBP_QUALITY));
            $webp[$target] = $variantPath;
        }

        $ogPath = $this->variantPath($path, 'og', 'jpg');
        $og = (clone $source)->scaleDown(width: self::OG_WIDTH);
        $storage->put($ogPath, (string) $og->toJpeg(self::JPEG_QUALITY));

        return [
            'width' => $width,
            'height' => $height,
            'variants' => ['webp' => $webp, 'og' => $ogPath],
        ];
    }

    /**
     * Apaga os ficheiros de um resultado antigo do generate().
     *
     * @param  array<string, mixed>|null  $variants
     */
    public function delete(string $disk, ?array $variants): void
    {
        if ($variants === null) {
            return;
        }

        $paths = array_values((array) ($variants['webp'] ?? []));

        if (is_string($variants['og'] ?? null)) {
            $paths[] = $variants['og'];
        }

        if ($paths !== []) {
            Storage::disk($disk)->delete($paths);
        }
    }

    /**
     * As larguras que cabem no original, e sempre pelo menos uma — uma
     * imagem de 200 px fica com uma versao WebP do seu tamanho.
     *
     * @return array<int, int>
     */
    private function widthsFor(int $width): array
    {
        $fitting = array_values(array_filter(self::WIDTHS, fn (int $target): bool => $target <= $width));

        return $fitting !== [] ? $fitting : [$width];
    }

    /** "products/abc.jpg" -> "products/variants/abc-640.webp" */
    private function variantPath(string $path, string $suffix, string $extension): string
    {
        $directory = pathinfo($path, PATHINFO_DIRNAME);
        $name = pathinfo($path, PATHINFO_FILENAME);
        $prefix = $directory === '.' ? '' : "$directory/";

        return "{$prefix}variants/{$name}-{$suffix}.{$extension}";
    }

    /**
     * Imagick quando existe: trabalha fora do memory_limit do PHP, e uma foto
     * de telemovel de 6000 px descomprimida em GD sao uns 100 MB dentro dele.
     */
    private function manager(): ImageManager
    {
        return new ImageManager(extension_loaded('imagick') ? new ImagickDriver : new GdDriver);
    }
}
