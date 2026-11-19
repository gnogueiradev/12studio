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
     * Duas passagens. Primeiro os valores que o cliente escreveu, sem olhar a
     * maiusculas nem a espacos ("ana  silva" apanha "Ana Silva"); um valor de
     * menos de 3 caracteres ("2") apagava meia mensagem e fica para a
     * segunda. Depois a mensagem toda, por forma: o que parece um email, e
     * qualquer sequencia de 6 ou mais digitos (telefones, NIF, codigos
     * postais, com espacos, pontos ou hifens pelo meio). Os ids do Stripe
     * (`cs_test_…`, `pi_…`) ficam: os digitos deles estao colados a letras
     * ou a `_`, e so se apagam digitos soltos.
     *
     * @param  array<int, string|null>  $values
     */
    public function withoutPersonalData(array $values): self
    {
        $message = $this->message;

        foreach ($values as $value) {
            $words = preg_split('/\s+/u', trim((string) $value), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            if (mb_strlen(implode(' ', $words)) < 3) {
                continue;
            }

            $pattern = '/'.implode('\s+', array_map(fn (string $word): string => preg_quote($word, '/'), $words)).'/iu';
            $message = (string) preg_replace($pattern, '[dados do cliente]', $message);
        }

        $message = (string) preg_replace('/[^\s@<>()\[\]"\',;:]+@[^\s@<>()\[\]"\',;:]+/u', '[email]', $message);
        $message = (string) preg_replace('/(?<!\w)\d(?:[ .\-]?\d){5,}(?!\w)/u', '[número]', $message);

        $this->message = $message;

        return new self($message, $this->status, $this->stripeCode, $this);
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
