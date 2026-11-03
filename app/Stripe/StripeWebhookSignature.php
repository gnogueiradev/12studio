<?php

namespace App\Stripe;

/**
 * Confere que um aviso (webhook) veio mesmo do Stripe.
 *
 * O endpoint esta aberto a internet, sem sessao nem password: o que o guarda e
 * esta conta. O Stripe assina cada aviso com o segredo do endpoint
 * (`whsec_…`, usado tal e qual como chave — ao contrario da Etsy, nao vai em
 * base64):
 *
 *   cabecalho  = "t=<timestamp>,v1=<assinatura>[,v1=<outra>]"
 *   assinatura = hex( HMAC-SHA256( timestamp + "." + corpo, segredo ) )
 *
 * O timestamp entra na assinatura e tem prazo: sem isso, quem apanhasse um
 * aviso verdadeiro podia reenvia-lo quando quisesse.
 */
class StripeWebhookSignature
{
    private const PREFIX = 'whsec_';

    /**
     * @param  string  $body  O corpo em BRUTO: reescrever o JSON mudava a assinatura.
     */
    public function isValid(string $body, ?string $header): bool
    {
        $secret = $this->secret();

        if ($secret === null || blank($header)) {
            return false;
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', (string) $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1') {
                // So o v1: o v0 e o esquema antigo, de teste.
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || ! ctype_digit($timestamp) || $signatures === []) {
            return false;
        }

        if (abs(now()->getTimestamp() - (int) $timestamp) > (int) config('stripe.webhook_tolerance', 300)) {
            return false;
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$body}", $secret);

        foreach ($signatures as $given) {
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
        return $this->secret() !== null;
    }

    private function secret(): ?string
    {
        $secret = config('stripe.webhook_secret');

        return is_string($secret) && str_starts_with($secret, self::PREFIX) && strlen($secret) > strlen(self::PREFIX) + 8
            ? $secret
            : null;
    }
}
