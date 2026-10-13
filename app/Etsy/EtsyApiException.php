<?php

namespace App\Etsy;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;

/**
 * A Etsy respondeu, e respondeu que nao.
 *
 * Leva o que ela disse: "Etsy API error (400)" nao diz a ninguem o que
 * corrigir, e "Invalid taxonomy_id" diz.
 */
class EtsyApiException extends EtsyException
{
    public function __construct(
        public readonly int $status,
        public readonly ?string $etsyError,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct(self::describe($status, $etsyError));
    }

    public static function fromResponse(Response $response): self
    {
        $error = $response->json('error_description') ?? $response->json('error');

        if (! is_string($error) || $error === '') {
            $error = Str::limit(trim($response->body()), 300) ?: null;
        }

        $retryAfter = $response->header('retry-after');

        return new self(
            $response->status(),
            $error === null ? null : Str::limit($error, 500),
            is_numeric($retryAfter) ? (int) $retryAfter : null,
        );
    }

    private static function describe(int $status, ?string $etsyError): string
    {
        $what = match (true) {
            $status === 401 => 'A Etsy não aceitou a autenticação',
            $status === 403 => 'A Etsy recusou o pedido (sem permissão)',
            $status === 404 => 'A Etsy não encontrou o que foi pedido',
            $status === 409 => 'A Etsy recusou por conflito com o que lá está',
            $status === 429 => 'A Etsy pediu para abrandar (limite de pedidos)',
            $status >= 500 => 'A Etsy está com problemas',
            default => 'A Etsy recusou o pedido',
        };

        return $etsyError === null
            ? "{$what} (HTTP {$status})."
            : "{$what} (HTTP {$status}): {$etsyError}";
    }
}
