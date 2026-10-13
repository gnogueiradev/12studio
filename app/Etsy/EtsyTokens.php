<?php

namespace App\Etsy;

use App\Alerts\SystemAlerts;
use App\Models\EtsyConnection;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Os tokens da loja ligada: guarda-los, entrega-los validos, renova-los.
 *
 * O de acesso dura uma hora. Ha tres processos a precisar dele (o backoffice,
 * a fila e o scheduler) e so um pode renovar de cada vez — o token de
 * renovacao e de uso unico, e dois pedidos em simultaneo com o mesmo deixavam
 * a ligacao partida.
 */
class EtsyTokens
{
    private const LOCK = 'etsy:token';

    public function __construct(
        private SystemAlerts $alerts,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('etsy.enabled');
    }

    public function isConfigured(): bool
    {
        return $this->filled(config('etsy.keystring')) && $this->filled(config('etsy.shared_secret'));
    }

    /**
     * "keystring:shared_secret", como a Etsy o quer no cabecalho x-api-key.
     */
    public function apiKey(): string
    {
        if (! $this->isConfigured()) {
            throw EtsyException::notConfigured();
        }

        return config('etsy.keystring').':'.config('etsy.shared_secret');
    }

    public function connection(): ?EtsyConnection
    {
        return EtsyConnection::query()->first();
    }

    public function isConnected(): bool
    {
        return $this->connection() !== null;
    }

    /**
     * Um token de acesso que ainda serve, renovado se for preciso.
     */
    public function accessToken(): string
    {
        $connection = $this->connection() ?? throw EtsyException::notConnected();

        if ($connection->isBroken()) {
            throw EtsyException::reconnect();
        }

        if ($this->stillValid($connection)) {
            return $connection->access_token;
        }

        try {
            return Cache::lock(self::LOCK, 30)->block((int) config('etsy.token_lock_wait', 15), function () use ($connection): string {
                // Relida ja com o cadeado na mao: se outro processo renovou
                // enquanto este esperava, o token de renovacao que este tinha
                // em memoria ja foi gasto. Usa-lo outra vez partia a ligacao.
                $fresh = $connection->fresh() ?? throw EtsyException::notConnected();

                if ($fresh->isBroken()) {
                    throw EtsyException::reconnect();
                }

                return $this->stillValid($fresh) ? $fresh->access_token : $this->refresh($fresh);
            });
        } catch (LockTimeoutException) {
            // Outro processo esta a renovar e demorou. Para quem chama e o
            // mesmo que a Etsy nao ter respondido — e e uma EtsyException que
            // as paginas e o MCP sabem mostrar, nao um erro 500.
            throw EtsyException::unreachable('outra renovação do token está a demorar');
        }
    }

    /**
     * Guarda o que a Etsy devolveu num pedido de token (o primeiro ou uma
     * renovacao). O token de renovacao grava-se SEMPRE: se a Etsy o rodar e
     * ficar ca o antigo, a ligacao morre na renovacao seguinte.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $attributes
     */
    public function store(array $payload, array $attributes = [], ?EtsyConnection $connection = null): EtsyConnection
    {
        // Numa renovacao vem a linha que se renovou; na ligacao da loja nao
        // vem nenhuma, e reaproveita-se a que houver (ha so uma loja).
        $connection ??= $this->connection() ?? new EtsyConnection;

        // A Etsy pode nao devolver um token de renovacao novo. O que ca esta
        // continua a servir; so numa primeira ligacao e que tem de vir.
        $refreshToken = is_string($payload['refresh_token'] ?? null) && $payload['refresh_token'] !== ''
            ? $payload['refresh_token']
            : ($connection->exists ? $connection->refresh_token : throw new EtsyException('A Etsy respondeu sem o token de renovação. Nada foi ligado.'));

        $connection->fill([
            ...$attributes,
            'access_token' => (string) $payload['access_token'],
            'refresh_token' => $refreshToken,
            'access_expires_at' => now()->addSeconds((int) ($payload['expires_in'] ?? 3600)),
            'broken_at' => null,
            'last_error' => null,
        ]);
        $connection->save();

        return $connection;
    }

    public function forget(): void
    {
        EtsyConnection::query()->delete();
    }

    private function stillValid(EtsyConnection $connection): bool
    {
        return $connection->access_expires_at->isAfter(now()->addSeconds((int) config('etsy.token_leeway', 120)));
    }

    private function refresh(EtsyConnection $connection): string
    {
        try {
            $response = Http::asForm()
                ->timeout((int) config('etsy.timeout', 15))
                ->withHeaders(['x-api-key' => $this->apiKey()])
                ->post((string) config('etsy.token_url'), [
                    'grant_type' => 'refresh_token',
                    'client_id' => (string) config('etsy.keystring'),
                    'refresh_token' => $connection->refresh_token,
                ]);
        } catch (ConnectionException $exception) {
            // Falha de rede nao e ligacao partida: a proxima chamada tenta.
            throw EtsyException::unreachable(Str::limit($exception->getMessage(), 120));
        }

        if ($response->successful() && is_string($response->json('access_token'))) {
            /** @var array<string, mixed> $payload */
            $payload = $response->json();

            return $this->store($payload, connection: $connection)->access_token;
        }

        $refusal = self::tokenRefusal($response);

        // So `invalid_grant` e a Etsy a dizer que ESTE token de renovacao
        // morreu (autorizacao retirada, 90 dias sem uso) — e so ai insistir
        // nao muda nada. Um limite de pedidos, uma chave trocada no .env ou
        // a Etsy em baixo recusam a renovacao sem a loja ter retirado nada:
        // marcar a ligacao como partida obrigava a refazer o OAuth por causa
        // de um soluco. Esses sobem como erro e a proxima chamada tenta.
        if ($response->status() === 400 && $response->json('error') === 'invalid_grant') {
            $this->markBroken($connection, $refusal);

            throw EtsyException::reconnect();
        }

        throw $refusal;
    }

    /**
     * O que o endpoint dos tokens recusou, SEM o corpo da resposta: e o unico
     * sitio onde um corpo inesperado pode trazer um token la dentro, e a
     * mensagem desta excecao acaba em toasts e no Discord.
     */
    public static function tokenRefusal(Response $response): EtsyApiException
    {
        $error = $response->json('error');
        $description = $response->json('error_description');

        return new EtsyApiException(
            $response->status(),
            match (true) {
                is_string($error) && is_string($description) => Str::limit("{$error}: {$description}", 300),
                is_string($error) => Str::limit($error, 300),
                default => null,
            },
        );
    }

    private function markBroken(EtsyConnection $connection, EtsyApiException $reason): void
    {
        $connection->update([
            'broken_at' => now(),
            'last_error' => Str::limit($reason->etsyError ?? "HTTP {$reason->status}", 500),
        ]);

        $this->alerts->etsyConnectionBroken($connection->shop_name, $connection->last_error);
    }

    private function filled(mixed $value): bool
    {
        return is_string($value) && $value !== '';
    }
}
