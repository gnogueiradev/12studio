<?php

namespace App\Stripe;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Tudo o que corre mal ao falar com o Stripe.
 *
 * A mensagem e escrita para quem esta do lado de ca e pode ser mostrada tal e
 * qual: diz o que aconteceu, nunca uma chave nem um stack trace.
 */
class StripeException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?string $stripeCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * A mesma falha sem os dados de um cliente na mensagem (o Stripe pode
     * repetir o email que recebeu), para poder ir para os registos. A
     * original fica como `previous`, pelo stack trace — e tambem sem eles,
     * porque os registos escrevem a mensagem da `previous`.
     *
     * @param  list<string>  $values
     */
    public function withoutPersonalData(array $values): self
    {
        $values = array_values(array_filter($values, fn (string $value): bool => trim($value) !== ''));
        $this->message = str_ireplace($values, '[dados do cliente]', $this->message);

        return new self($this->message, $this->status, $this->stripeCode, $this);
    }

    public static function disabled(): self
    {
        return new self('A integração com o Stripe está desligada no servidor (STRIPE_ENABLED=false). Nada foi enviado.');
    }

    public static function unreachable(string $reason): self
    {
        return new self("Não foi possível falar com o Stripe ({$reason}). Não se sabe se o pedido chegou — confirma antes de repetir.");
    }

    /**
     * O Stripe respondeu, e respondeu que nao. Leva o que ele disse: "erro
     * 400" nao diz a ninguem o que corrigir.
     */
    public static function fromResponse(Response $response): self
    {
        $message = $response->json('error.message');
        $code = $response->json('error.code');

        if (! is_string($message) || $message === '') {
            $message = Str::limit(trim($response->body()), 300) ?: 'sem detalhe';
        }

        return new self(
            "O Stripe recusou o pedido ({$response->status()}): ".Str::limit($message, 500),
            $response->status(),
            is_string($code) ? $code : null,
        );
    }
}
