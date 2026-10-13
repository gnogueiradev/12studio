<?php

namespace App\Etsy;

/**
 * Confere que um aviso (webhook) veio mesmo da Etsy.
 *
 * O endpoint dos avisos esta aberto a internet, sem sessao nem password: o
 * que o guarda e esta conta. A Etsy assina cada aviso com um segredo que so
 * ela e nos temos (o do portal, `whsec_…`):
 *
 *   assinado   = id + "." + timestamp + "." + corpo, tal e qual chegou
 *   assinatura = base64( HMAC-SHA256( assinado, base64_decode(segredo sem o prefixo) ) )
 *
 * O timestamp entra na assinatura e tem prazo: sem isso, quem apanhasse um
 * aviso verdadeiro podia reenvia-lo quando quisesse.
 */
class EtsyWebhookSignature
{
    private const PREFIX = 'whsec_';

    /** Menos do que isto nao e um segredo: adivinha-se. Os da Etsy tem 24 ou mais. */
    private const MIN_KEY_BYTES = 16;

    /**
     * @param  string  $body  O corpo em BRUTO: reescrever o JSON mudava a assinatura.
     */
    public function isValid(string $body, ?string $id, ?string $timestamp, ?string $signatures): bool
    {
        $key = $this->key();

        if ($key === null || blank($id) || blank($signatures) || ! is_string($timestamp) || ! ctype_digit($timestamp)) {
            return false;
        }

        if (abs(now()->getTimestamp() - (int) $timestamp) > (int) config('etsy.webhook_tolerance', 300)) {
            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", $key, true));

        // Pode vir mais de uma, separadas por espaco (quando o segredo e
        // trocado), cada uma com a versao a frente: "v1,<assinatura>".
        foreach (preg_split('/\s+/', trim((string) $signatures)) ?: [] as $candidate) {
            $given = str_contains($candidate, ',') ? substr($candidate, (int) strpos($candidate, ',') + 1) : $candidate;

            // Comparacao em tempo constante: uma que parasse no primeiro
            // caracter errado dizia a quem tenta quantos acertou.
            if (hash_equals($expected, $given)) {
                return true;
            }
        }

        return false;
    }

    public function isConfigured(): bool
    {
        return $this->key() !== null;
    }

    /**
     * A chave em bytes, ou null se o segredo nao esta posto ou nao tem a
     * forma que a Etsy lhe da.
     */
    private function key(): ?string
    {
        $secret = config('etsy.webhook_secret');

        if (! is_string($secret) || ! str_starts_with($secret, self::PREFIX)) {
            return null;
        }

        $key = base64_decode(substr($secret, strlen(self::PREFIX)), true);

        return $key === false || strlen($key) < self::MIN_KEY_BYTES ? null : $key;
    }
}
