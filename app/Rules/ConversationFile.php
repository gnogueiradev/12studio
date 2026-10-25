<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Um ficheiro de uma conversa: a extensao tem de estar na lista e o conteudo
 * tem de bater com ela (os primeiros bytes). Um ".stl" que e um script, ou um
 * ".jpg" que e outra coisa, nao entra. SVG nunca: pode levar <script>.
 *
 *   3MF  zip ("PK\3\4")
 *   STL  binario: 84 + 50 * n bytes (n no offset 80); ou texto a comecar por "solid"
 *   OBJ  texto (sem bytes nulos)
 *   STEP texto a comecar por "ISO-10303-21"
 *   JPG, PNG, WebP e PDF pela assinatura de cada um
 */
class ConversationFile implements ValidationRule
{
    public const EXTENSIONS = ['stl', '3mf', 'obj', 'step', 'stp', 'jpg', 'jpeg', 'png', 'webp', 'pdf'];

    /** Por ficheiro (cabe no upload_max_filesize do docker/php.ini). */
    public const MAX_KB = 20 * 1024;

    /** Todos juntos (cabe no post_max_size, com folga para os campos). */
    public const MAX_TOTAL_KB = 20 * 1024;

    public const MAX_FILES = 3;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail(__('validation.uploaded', ['attribute' => $attribute]));

            return;
        }

        $extension = strtolower($value->getClientOriginalExtension());

        if (! in_array($extension, self::EXTENSIONS, true) || ! $this->matches($extension, $value)) {
            $fail(trans('store.conversation.file_type'));
        }
    }

    private function matches(string $extension, UploadedFile $file): bool
    {
        $path = $file->getRealPath();

        if ($path === false) {
            return false;
        }

        $head = (string) file_get_contents($path, false, null, 0, 1024);
        $size = (int) $file->getSize();

        return match ($extension) {
            '3mf' => str_starts_with($head, "PK\x03\x04"),
            'stl' => $this->isStl($head, $size),
            'obj' => $head !== '' && ! str_contains($head, "\0"),
            'step', 'stp' => str_starts_with(ltrim($head), 'ISO-10303-21'),
            'jpg', 'jpeg' => str_starts_with($head, "\xFF\xD8\xFF"),
            'png' => str_starts_with($head, "\x89PNG\r\n\x1A\n"),
            'webp' => str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP',
            'pdf' => str_starts_with($head, '%PDF-'),
            default => false,
        };
    }

    private function isStl(string $head, int $size): bool
    {
        if (str_starts_with(ltrim($head), 'solid') && ! str_contains($head, "\0")) {
            return true;
        }

        if ($size < 84 || strlen($head) < 84) {
            return false;
        }

        /** @var array{1: int} $count */
        $count = unpack('V', substr($head, 80, 4));

        return $size === 84 + 50 * $count[1];
    }
}
