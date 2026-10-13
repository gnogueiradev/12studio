<?php

namespace App\Etsy;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/**
 * A unica porta de saida para a Etsy.
 *
 * Tres regras vivem aqui para nao terem de ser lembradas em cada chamada:
 *
 *   - com a integracao desligada (ETSY_ENABLED) nao sai pedido nenhum;
 *   - so as LEITURAS se repetem sozinhas. Um POST que criou o anuncio e
 *     depois perdeu a ligacao criava outro a segunda — quem escreve decide se
 *     repete, depois de ir ver o que la ficou;
 *   - quando a Etsy recusa, o que ela disse sobe na excecao.
 *
 * A Etsy usa tres codificacoes consoante o endpoint, e cada metodo de escrita
 * diz qual e a sua no nome: form (a maioria), json (inventario,
 * personalizacao, envios) e multipart (ficheiros).
 */
class EtsyClient
{
    /** Onde ficam os ultimos limites de pedidos que a Etsy reportou. */
    public const LIMITS_CACHE_KEY = 'etsy:limits';

    /** Tentativas de uma leitura (a primeira conta). */
    private const READ_ATTEMPTS = 3;

    /** Teto, em segundos, do que se espera por um retry-after. */
    private const MAX_WAIT = 10;

    /** Token imposto por fora, so durante a troca do OAuth (ainda nao ha linha). */
    private ?string $token = null;

    public function __construct(
        private EtsyTokens $tokens,
    ) {}

    /**
     * Uma copia que usa este token em vez do guardado. Serve a ligacao da
     * loja: os primeiros pedidos fazem-se antes de haver o que guardar.
     */
    public function withToken(string $token): static
    {
        $clone = clone $this;
        $clone->token = $token;

        return $clone;
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  bool  $public  Leituras que so pedem a chave da app (pesquisa, taxonomia, avaliacoes).
     * @return array<array-key, mixed>
     */
    public function get(string $path, array $query = [], bool $public = false): array
    {
        return $this->send(
            fn (PendingRequest $http): Response => $http->get($this->url($path), $this->flatten($query)),
            public: $public,
            retry: true,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function postForm(string $path, array $data = []): array
    {
        return $this->send(fn (PendingRequest $http): Response => $http->asForm()->post($this->url($path), $this->flatten($data)));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function putForm(string $path, array $data = []): array
    {
        return $this->send(fn (PendingRequest $http): Response => $http->asForm()->put($this->url($path), $this->flatten($data)));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function patchForm(string $path, array $data = []): array
    {
        return $this->send(fn (PendingRequest $http): Response => $http->asForm()->patch($this->url($path), $this->flatten($data)));
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $query
     * @return array<array-key, mixed>
     */
    public function postJson(string $path, array $data = [], array $query = []): array
    {
        return $this->send(fn (PendingRequest $http): Response => $http->asJson()->post($this->url($path, $query), $data));
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $query
     * @return array<array-key, mixed>
     */
    public function putJson(string $path, array $data = [], array $query = []): array
    {
        return $this->send(fn (PendingRequest $http): Response => $http->asJson()->put($this->url($path, $query), $data));
    }

    /**
     * Um ficheiro (fotografia, video) e os campos que o acompanham.
     *
     * @param  array<string, mixed>  $fields
     * @return array<array-key, mixed>
     */
    public function postMultipart(string $path, string $fileField, string $contents, string $filename, array $fields = []): array
    {
        return $this->send(fn (PendingRequest $http): Response => $http
            ->attach($fileField, $contents, $filename)
            ->post($this->url($path), $this->flatten($fields)));
    }

    /**
     * @return array<array-key, mixed>
     */
    public function delete(string $path): array
    {
        return $this->send(fn (PendingRequest $http): Response => $http->delete($this->url($path)));
    }

    /**
     * Os limites de pedidos que a Etsy reportou na ultima resposta, ou null
     * se ainda nao houve nenhuma.
     *
     * @return array{per_second: int|null, remaining_second: int|null, per_day: int|null, remaining_today: int|null, at: string}|null
     */
    public function limits(): ?array
    {
        $limits = Cache::get(self::LIMITS_CACHE_KEY);

        if (! is_array($limits)) {
            return null;
        }

        // Relido campo a campo: o que sai da cache e o que la estiver, e uma
        // entrada de outra versao nao pode chegar ao MCP com outra forma.
        $number = fn (string $key): ?int => is_int($limits[$key] ?? null) ? $limits[$key] : null;

        return [
            'per_second' => $number('per_second'),
            'remaining_second' => $number('remaining_second'),
            'per_day' => $number('per_day'),
            'remaining_today' => $number('remaining_today'),
            'at' => is_string($limits['at'] ?? null) ? $limits['at'] : '',
        ];
    }

    /**
     * @param  Closure(PendingRequest): Response  $call
     * @return array<array-key, mixed>
     */
    private function send(Closure $call, bool $public = false, bool $retry = false): array
    {
        if (! $this->tokens->isEnabled()) {
            throw EtsyException::disabled();
        }

        // Antes do ciclo: sem chave ou sem loja nao ha pedido que valha a
        // pena tentar tres vezes.
        $headers = ['x-api-key' => $this->tokens->apiKey()];
        $token = $public ? null : ($this->token ?? $this->tokens->accessToken());

        $attempts = $retry ? self::READ_ATTEMPTS : 1;

        for ($attempt = 1; ; $attempt++) {
            $http = Http::timeout((int) config('etsy.timeout', 15))
                ->acceptJson()
                ->withHeaders($headers);

            if ($token !== null) {
                $http = $http->withToken($token);
            }

            try {
                $response = $call($http);
            } catch (ConnectionException $exception) {
                if ($attempt < $attempts) {
                    Sleep::for($attempt)->seconds();

                    continue;
                }

                throw EtsyException::unreachable(Str::limit($exception->getMessage(), 120));
            }

            $this->rememberLimits($response);

            if ($response->successful()) {
                $json = $response->json();

                // 204 (apagar) nao traz corpo.
                return is_array($json) ? $json : [];
            }

            $error = EtsyApiException::fromResponse($response);

            if ($attempt < $attempts && ($response->status() === 429 || $response->serverError())) {
                // O que a Etsy mandou esperar, nao um numero nosso — com teto,
                // para um retry-after de uma hora nao prender um pedido.
                Sleep::for(min($error->retryAfter ?? $attempt, self::MAX_WAIT))->seconds();

                continue;
            }

            throw $error;
        }
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function url(string $path, array $query = []): string
    {
        $url = rtrim((string) config('etsy.api_url'), '/').'/'.ltrim($path, '/');
        $query = $this->flatten($query);

        return $query === [] ? $url : $url.'?'.http_build_query($query);
    }

    /**
     * Como a Etsy le um formulario: listas separadas por virgulas, booleanos
     * por extenso, e o que e null simplesmente nao vai.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function flatten(array $data): array
    {
        $flat = [];

        foreach ($data as $key => $value) {
            if ($value === null) {
                continue;
            }

            $flat[$key] = match (true) {
                is_bool($value) => $value ? 'true' : 'false',
                is_array($value) => implode(',', array_map(
                    fn (mixed $item): string => is_bool($item) ? ($item ? 'true' : 'false') : (string) $item,
                    $value,
                )),
                default => (string) $value,
            };
        }

        return $flat;
    }

    private function rememberLimits(Response $response): void
    {
        $perDay = $response->header('x-limit-per-day');

        if ($perDay === '') {
            return;
        }

        $number = fn (string $header): ?int => is_numeric($response->header($header)) ? (int) $response->header($header) : null;

        Cache::put(self::LIMITS_CACHE_KEY, [
            'per_second' => $number('x-limit-per-second'),
            'remaining_second' => $number('x-remaining-this-second'),
            'per_day' => $number('x-limit-per-day'),
            'remaining_today' => $number('x-remaining-today'),
            'at' => now()->format('Y-m-d H:i'),
        ], now()->addDay());
    }
}
