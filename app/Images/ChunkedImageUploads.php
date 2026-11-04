<?php

namespace App\Images;

use App\Mcp\CurrentToken;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Fotografias que chegam as fatias pelo MCP (product_image_upload_*).
 *
 * Um cliente LLM nao consegue mandar 400 mil caracteres de base64 numa so
 * chamada sem errar um: aqui o ficheiro chega em pedacos de bytes, cada um com
 * o seu sha256, e so no fim se junta e se confere o hash do ficheiro inteiro.
 * Dai para a frente e exatamente o caminho do product_image_add.
 *
 * Os pedacos vivem no disco privado (storage/app/private/mcp-uploads/{id}/),
 * nunca no publico: um ficheiro por pedaco (escrito num temporario e depois
 * renomeado, por isso chamadas em paralelo nao se pisam) e um meta.json com o
 * que o start prometeu e a quem pertence. Os que expiram vao-se ao abrir o
 * proximo upload — nao ha agendamento para isto.
 */
class ChunkedImageUploads
{
    private const DIR = 'mcp-uploads';

    private const META = 'meta.json';

    /**
     * @param  array<string, mixed>  $meta  o que o start validou (produto, hash, tamanhos...)
     * @return array<string, mixed> o meta gravado, com id e expires_at
     */
    public function start(string $owner, array $meta): array
    {
        $this->pruneExpired();

        $id = Str::random(40);
        $now = now();

        $meta = [
            ...$meta,
            'id' => $id,
            'owner' => $owner,
            'created_at' => $now->getTimestamp(),
            'expires_at' => $now->addMinutes($this->ttlMinutes())->getTimestamp(),
        ];

        $this->disk()->makeDirectory($this->dir($id));
        $this->atomicPut($this->dir($id).'/'.self::META, (string) json_encode($meta));

        return $meta;
    }

    /**
     * O upload, se existir, nao tiver expirado e for de quem pergunta. Senao,
     * o motivo (`upload_desconhecido`, `upload_expirado`, `upload_alheio`),
     * que e so para o log: para quem chama a resposta e sempre a mesma, e quem
     * nao e dono nao fica a saber que o id existe.
     *
     * @return array<string, mixed>|string
     */
    public function find(string $id, string $owner): array|string
    {
        if (! self::validId($id)) {
            return 'upload_desconhecido';
        }

        $raw = $this->disk()->get($this->dir($id).'/'.self::META);
        $meta = is_string($raw) ? json_decode($raw, true) : null;

        if (! is_array($meta)) {
            return 'upload_desconhecido';
        }

        if ((int) ($meta['expires_at'] ?? 0) < now()->getTimestamp()) {
            $this->forget($id);

            return 'upload_expirado';
        }

        return hash_equals((string) ($meta['owner'] ?? ''), $owner) ? $meta : 'upload_alheio';
    }

    public function putChunk(string $id, int $index, string $bytes): void
    {
        $this->atomicPut($this->chunkPath($id, $index), $bytes);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{received: list<int>, missing: list<int>}
     */
    public function progress(array $meta): array
    {
        $count = (int) $meta['chunk_count'];
        $received = [];

        foreach ($this->disk()->files($this->dir((string) $meta['id'])) as $file) {
            if (preg_match('/\/chunk-(\d+)\.bin$/', $file, $match) === 1 && (int) $match[1] < $count) {
                $received[] = (int) $match[1];
            }
        }

        sort($received);

        return [
            'received' => $received,
            'missing' => array_values(array_diff(range(0, $count - 1), $received)),
        ];
    }

    /**
     * Junta os pedacos, por ordem, num ficheiro temporario fora do disco (o
     * IncomingImage fica com ele). Quem chama confere o tamanho e o hash.
     *
     * @param  array<string, mixed>  $meta
     */
    public function assemble(array $meta): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mcp-img-');

        if ($path === false) {
            throw new RuntimeException('Nao foi possivel criar um ficheiro temporario.');
        }

        try {
            $out = fopen($path, 'wb');

            if ($out === false) {
                throw new RuntimeException('Nao foi possivel escrever um ficheiro temporario.');
            }

            try {
                for ($index = 0; $index < (int) $meta['chunk_count']; $index++) {
                    $in = $this->disk()->readStream($this->chunkPath((string) $meta['id'], $index));

                    if (! is_resource($in)) {
                        throw new RuntimeException("Pedaco {$index} desapareceu a meio.");
                    }

                    stream_copy_to_stream($in, $out);
                    fclose($in);
                }
            } finally {
                fclose($out);
            }
        } catch (Throwable $exception) {
            @unlink($path);

            throw $exception;
        }

        return $path;
    }

    public function forget(string $id): void
    {
        if (self::validId($id)) {
            $this->disk()->deleteDirectory($this->dir($id));
        }
    }

    /**
     * Apaga os uploads expirados (ou sem meta legivel e mais velhos que o TTL).
     */
    public function pruneExpired(): int
    {
        $now = now()->getTimestamp();
        $pruned = 0;

        foreach ($this->disk()->directories(self::DIR) as $dir) {
            $raw = $this->disk()->get($dir.'/'.self::META);
            $meta = is_string($raw) ? json_decode($raw, true) : null;

            $expires = is_array($meta)
                ? (int) ($meta['expires_at'] ?? 0)
                : (int) @filemtime($this->disk()->path($dir)) + $this->ttlMinutes() * 60;

            if ($expires < $now) {
                $this->disk()->deleteDirectory($dir);
                $pruned++;
            }
        }

        return $pruned;
    }

    /**
     * A quem pertence um upload: ao token do Passport com que foi aberto (ou,
     * sem token, a conta). Outra chave, mesmo da mesma conta, nao o acaba.
     */
    public static function ownerOf(User $user): string
    {
        $token = CurrentToken::id($user);

        return $token !== null ? 'token:'.$token : 'user:'.$user->getKey();
    }

    public function ttlMinutes(): int
    {
        return max(1, (int) config('mcp.images.upload_ttl_minutes'));
    }

    public static function validId(string $id): bool
    {
        return preg_match('/^[A-Za-z0-9]{40}$/', $id) === 1;
    }

    private function atomicPut(string $relative, string $contents): void
    {
        $target = $this->disk()->path($relative);
        $temp = $target.'.'.Str::random(8).'.tmp';

        // Com @: se o upload foi cancelado entretanto, a pasta ja nao existe.
        if (@file_put_contents($temp, $contents) === false || ! @rename($temp, $target)) {
            @unlink($temp);

            throw new RuntimeException('Nao foi possivel gravar um pedaco do upload.');
        }
    }

    private function chunkPath(string $id, int $index): string
    {
        return $this->dir($id)."/chunk-{$index}.bin";
    }

    private function dir(string $id): string
    {
        return self::DIR.'/'.$id;
    }

    private function disk(): FilesystemAdapter
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk('local');

        return $disk;
    }
}
