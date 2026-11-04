<?php

namespace App\Images;

use finfo;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use Throwable;

/**
 * Uma imagem que veio de fora, ja validada e num ficheiro temporario. O que se
 * olha e o conteudo real: a extensao e o Content-Type sao o que o outro lado
 * quis dizer.
 */
final readonly class IncomingImage
{
    private const MIMES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    private function __construct(
        public string $path,
        public string $mime,
        public int $bytes,
        public int $width,
        public int $height,
    ) {}

    /**
     * @throws ImageRefused
     */
    public static function fromBase64(string $encoded): self
    {
        $encoded = (string) preg_replace('/^data:image\/[a-z0-9.+-]+;base64,/i', '', $encoded, 1);
        $max = (int) config('mcp.images.max_bytes');

        // Antes de descodificar: a memoria nao paga o que depois se recusa.
        if (strlen($encoded) > (int) ceil($max / 3) * 4) {
            throw new ImageRefused('demasiado_grande', 'A imagem passa do máximo de '.round($max / 1024 / 1024, 1).' MB.');
        }

        $bytes = base64_decode($encoded, true);

        if ($bytes === false || $bytes === '') {
            throw new ImageRefused('base64_invalido', 'O image_base64 não é base64 válido.');
        }

        $path = tempnam(sys_get_temp_dir(), 'mcp-img-');

        if ($path === false) {
            throw new RuntimeException('Nao foi possivel criar um ficheiro temporario.');
        }

        if (file_put_contents($path, $bytes) === false) {
            @unlink($path);

            throw new RuntimeException('Nao foi possivel escrever um ficheiro temporario.');
        }

        return self::fromFile($path);
    }

    /**
     * Fica com o ficheiro: se a imagem for recusada, apaga-o.
     *
     * @throws ImageRefused
     */
    public static function fromFile(string $path): self
    {
        try {
            return self::validate($path);
        } catch (Throwable $exception) {
            @unlink($path);

            throw $exception;
        }
    }

    public function uploadedFile(): UploadedFile
    {
        // `test: true` porque nao veio por um POST (is_uploaded_file diria que
        // nao). O nome no disco e um hash do Laravel, e a extensao sai do
        // conteudo: este nome nao chega a ser usado.
        return new UploadedFile($this->path, 'imagem.'.self::MIMES[$this->mime], $this->mime, null, true);
    }

    public function discard(): void
    {
        @unlink($this->path);
    }

    /**
     * @return array{mime: string, bytes: int, width: int, height: int}
     */
    public function context(): array
    {
        return ['mime' => $this->mime, 'bytes' => $this->bytes, 'width' => $this->width, 'height' => $this->height];
    }

    private static function validate(string $path): self
    {
        $max = (int) config('mcp.images.max_bytes');
        $bytes = (int) filesize($path);

        if ($bytes > $max) {
            throw new ImageRefused('demasiado_grande', 'A imagem passa do máximo de '.round($max / 1024 / 1024, 1).' MB.');
        }

        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($path);
        $size = array_key_exists($mime, self::MIMES) ? @getimagesize($path) : false;

        if ($size === false) {
            throw new ImageRefused('mime_invalido', 'Só se aceitam imagens JPG, PNG ou WEBP, e este ficheiro não é nenhuma delas.');
        }

        [$width, $height] = $size;
        $minSide = (int) config('mcp.images.min_side');

        if (min($width, $height) < $minSide) {
            throw new ImageRefused('dimensoes_pequenas', "A imagem tem {$width}×{$height} px: o lado menor tem de ter pelo menos {$minSide} px.");
        }

        // Poucos MB de PNG podem descomprimir para varios GB no job das versoes
        // reduzidas. O tamanho do ficheiro nao apanha isto.
        $maxMegapixels = (float) config('mcp.images.max_megapixels');

        if ($width * $height > $maxMegapixels * 1_000_000) {
            throw new ImageRefused('dimensoes_grandes', "A imagem tem {$width}×{$height} px: passa do máximo de {$maxMegapixels} megapíxeis.");
        }

        return new self($path, $mime, $bytes, $width, $height);
    }
}
