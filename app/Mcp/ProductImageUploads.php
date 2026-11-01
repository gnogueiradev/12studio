<?php

namespace App\Mcp;

use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Links de upload de fotografias para o MCP.
 *
 * Porque nao vai a imagem dentro da chamada da ferramenta: os argumentos de
 * uma ferramenta MCP sao JSON escrito pelo modelo, token a token. Uma foto de
 * 2 MB em base64 sao ~2,7 milhoes de caracteres — lento, caro e facil de
 * corromper. Em vez disso, a ferramenta product_image_upload_url devolve um
 * link e o ficheiro segue por HTTP normal (multipart), como no backoffice.
 *
 * Cada link:
 *   - e assinado (middleware `signed`) e expira em TTL_MINUTES;
 *   - serve UMA vez: o nonce vive na cache e e consumido atomicamente;
 *   - so leva o nonce no endereco — produto, texto alternativo e quem o pediu
 *     ficam do lado do servidor, para nao andarem em logs de proxies.
 */
final class ProductImageUploads
{
    public const TTL_MINUTES = 15;

    private const PREFIX = 'mcp-image-upload:';

    /**
     * @return array{url: string, expires_at: Carbon}
     */
    public function issue(Product $product, User $user, ?string $alt, bool $makePrimary): array
    {
        $nonce = Str::random(40);
        $expiresAt = now()->addMinutes(self::TTL_MINUTES);

        Cache::put(self::PREFIX.$nonce, [
            'product_id' => $product->getKey(),
            'user_id' => $user->getKey(),
            'alt' => $alt,
            'make_primary' => $makePrimary,
        ], $expiresAt);

        return [
            'url' => URL::temporarySignedRoute('mcp.product-images.upload', $expiresAt, ['upload' => $nonce]),
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * O pedido pendente, sem o gastar. Serve para recusar cedo um link morto
     * sem queimar um link vivo por causa de um ficheiro invalido.
     *
     * @return array{product_id: int, user_id: int, alt: string|null, make_primary: bool}|null
     */
    public function peek(string $nonce): ?array
    {
        /** @var array{product_id: int, user_id: int, alt: string|null, make_primary: bool}|null $pending */
        $pending = Cache::get(self::PREFIX.$nonce);

        return is_array($pending) ? $pending : null;
    }

    /**
     * Gasta o link. So um pedido ganha: dois uploads em simultaneo com o mesmo
     * link (reenvio do cliente, duplo clique) dariam duas fotografias, e o
     * get+forget da cache sozinho nao e atomico.
     */
    public function consume(string $nonce): bool
    {
        $lock = Cache::lock(self::PREFIX.'lock:'.$nonce, 10);

        if (! $lock->get()) {
            return false;
        }

        try {
            return Cache::pull(self::PREFIX.$nonce) !== null;
        } finally {
            $lock->release();
        }
    }
}
