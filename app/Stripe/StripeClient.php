<?php

namespace App\Stripe;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/**
 * A unica porta de saida para o Stripe. Sem o SDK: sao meia duzia de
 * endpoints, e o SDK obrigava a mexer no composer.lock (o Laravel esta preso
 * na 13.34).
 *
 * As regras da EtsyClient, pelas mesmas razoes:
 *
 *   - com a integracao desligada (STRIPE_ENABLED) nao sai pedido nenhum;
 *   - so as LEITURAS se repetem sozinhas. Uma escrita repetida as cegas pode
 *     criar duas sessoes ou dois precos — quem escreve passa uma chave de
 *     idempotencia, e com ela o Stripe devolve o resultado da primeira;
 *   - quando o Stripe recusa, o que ele disse sobe na excecao.
 *
 * O Stripe le formularios com a notacao dos colchetes (line_items[0][price]),
 * que e o que o http_build_query do asForm() ja escreve.
 */
class StripeClient
{
    /** Tentativas de uma leitura (a primeira conta). */
    private const READ_ATTEMPTS = 3;

    public function isEnabled(): bool
    {
        return (bool) config('stripe.enabled') && filled(config('stripe.secret'));
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return $this->send(
            fn (PendingRequest $http): Response => $http->get($this->url($path), $this->normalize($query)),
            retry: true,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  string|null  $idempotencyKey  A mesma chave em duas chamadas = um so efeito.
     * @return array<string, mixed>
     */
    public function post(string $path, array $data = [], ?string $idempotencyKey = null): array
    {
        return $this->send(function (PendingRequest $http) use ($path, $data, $idempotencyKey): Response {
            if ($idempotencyKey !== null) {
                $http = $http->withHeaders(['Idempotency-Key' => $idempotencyKey]);
            }

            return $http->asForm()->post($this->url($path), $this->normalize($data));
        });
    }

    /**
     * @param  Closure(PendingRequest): Response  $call
     * @return array<string, mixed>
     */
    private function send(Closure $call, bool $retry = false): array
    {
        if (! $this->isEnabled()) {
            throw StripeException::disabled();
        }

        $attempts = $retry ? self::READ_ATTEMPTS : 1;

        for ($attempt = 1; ; $attempt++) {
            $http = Http::timeout((int) config('stripe.timeout', 15))
                ->acceptJson()
                ->withToken((string) config('stripe.secret'));

            if (filled(config('stripe.api_version'))) {
                $http = $http->withHeaders(['Stripe-Version' => (string) config('stripe.api_version')]);
            }

            try {
                $response = $call($http);
            } catch (ConnectionException $exception) {
                if ($attempt < $attempts) {
                    Sleep::for($attempt)->seconds();

                    continue;
                }

                throw StripeException::unreachable(Str::limit($exception->getMessage(), 120));
            }

            if ($response->successful()) {
                $json = $response->json();

                return is_array($json) ? $json : [];
            }

            if ($attempt < $attempts && ($response->status() === 429 || $response->serverError())) {
                Sleep::for($attempt)->seconds();

                continue;
            }

            throw StripeException::fromResponse($response);
        }
    }

    private function url(string $path): string
    {
        return rtrim((string) config('stripe.api_url'), '/').'/'.ltrim($path, '/');
    }

    /**
     * Como o Stripe le um formulario: booleanos por extenso e o que e null
     * nao vai. Os arrays ficam arrays — o http_build_query poe-lhes os
     * colchetes.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function normalize(array $data): array
    {
        $clean = [];

        foreach ($data as $key => $value) {
            if ($value === null) {
                continue;
            }

            $clean[$key] = match (true) {
                is_bool($value) => $value ? 'true' : 'false',
                is_array($value) => $this->normalize($value),
                default => $value,
            };
        }

        return $clean;
    }
}
