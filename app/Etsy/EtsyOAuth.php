<?php

namespace App\Etsy;

use App\Models\EtsyConnection;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Ligar a loja: OAuth 2.0 com PKCE, como a Etsy o exige.
 *
 * O 12studio e aqui o CLIENTE (ao contrario do /mcp, onde e o servidor). Sao
 * duas idas: `authorizationUrl()` manda o dono a Etsy com um desafio, e
 * `complete()` recebe-o de volta com um codigo e troca-o pelos tokens,
 * provando com o verificador que quem volta e quem saiu.
 */
class EtsyOAuth
{
    /** Onde o `state` e o verificador esperam pelo regresso. */
    public const SESSION_KEY = 'etsy.oauth';

    public function __construct(
        private EtsyTokens $tokens,
        private EtsyClient $client,
    ) {}

    /**
     * Tem de coincidir, letra a letra, com um dos Callback URLs da app.
     */
    public function redirectUri(): string
    {
        $configured = config('etsy.redirect_uri');

        return is_string($configured) && $configured !== ''
            ? $configured
            : route('admin.etsy.callback');
    }

    public function authorizationUrl(Request $request): string
    {
        if (! $this->tokens->isEnabled()) {
            throw EtsyException::disabled();
        }

        if (! $this->tokens->isConfigured()) {
            throw EtsyException::notConfigured();
        }

        // 64 caracteres alfanumericos: dentro dos 43-128 que o PKCE pede, e
        // sem nada que precise de ser escapado.
        $verifier = Str::random(64);
        $state = Str::random(40);

        // So o desafio (o SHA-256 do verificador) passa pelo browser. O
        // verificador fica na sessao e so sai na troca, de servidor a servidor.
        $request->session()->put(self::SESSION_KEY, ['state' => $state, 'verifier' => $verifier]);

        return config('etsy.authorize_url').'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => (string) config('etsy.keystring'),
            'redirect_uri' => $this->redirectUri(),
            'scope' => implode(' ', (array) config('etsy.scopes')),
            'state' => $state,
            'code_challenge' => self::challenge($verifier),
            'code_challenge_method' => 'S256',
        ], encoding_type: PHP_QUERY_RFC3986);
    }

    /**
     * O regresso da Etsy. Lanca EtsyException com a razao, em vez de ligar
     * pela metade.
     */
    public function complete(Request $request, User $by): EtsyConnection
    {
        // pull(): um pedido de ligacao serve uma vez. Repetir o callback com
        // o mesmo codigo ja nao encontra com que o comparar.
        $pending = $request->session()->pull(self::SESSION_KEY);

        if (! is_array($pending) || ! is_string($pending['state'] ?? null) || ! is_string($pending['verifier'] ?? null)) {
            throw new EtsyException('Este regresso da Etsy não corresponde a nenhum pedido de ligação feito aqui. Começa outra vez em "Ligar à Etsy".');
        }

        // Antes de tudo o resto: um `state` que nao e o nosso e um pedido que
        // nao comecou aqui, e esse nao liga loja nenhuma a esta conta.
        if (! hash_equals($pending['state'], (string) $request->query('state', ''))) {
            throw new EtsyException('O regresso da Etsy não bate com o pedido de ligação (state diferente). Nada foi ligado — começa outra vez.');
        }

        if ($request->query('error') !== null) {
            throw new EtsyException('A ligação não foi autorizada na Etsy. Nada foi ligado.');
        }

        $code = (string) $request->query('code', '');

        if ($code === '') {
            throw new EtsyException('A Etsy não devolveu o código de autorização. Nada foi ligado.');
        }

        $tokens = $this->exchange($code, $pending['verifier']);
        $client = $this->client->withToken((string) $tokens['access_token']);

        $me = $client->get('/users/me');
        $shopId = $me['shop_id'] ?? null;

        if (! is_numeric($shopId)) {
            throw new EtsyException('Esta conta da Etsy não tem nenhuma loja. Nada foi ligado.');
        }

        $shop = $client->get('/shops/'.(int) $shopId, public: true);
        $existing = $this->tokens->connection();

        return $this->tokens->store($tokens, [
            'shop_id' => (int) $shopId,
            'shop_name' => Str::limit((string) ($shop['shop_name'] ?? "Loja {$shopId}"), 120, ''),
            'etsy_user_id' => (int) ($me['user_id'] ?? 0),
            'scopes' => implode(' ', (array) config('etsy.scopes')),
            'connected_by_user_id' => $by->getKey(),
            // Religar a MESMA loja nao e comecar de novo: as encomendas que
            // entraram com a ligacao partida ainda tem de vir. Outra loja, ou
            // a primeira vez, conta daqui para a frente.
            'orders_since' => $existing !== null && $existing->shop_id === (int) $shopId && $existing->orders_since !== null
                ? $existing->orders_since
                : now(),
        ]);
    }

    public static function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /**
     * @return array<string, mixed>
     */
    private function exchange(string $code, string $verifier): array
    {
        try {
            $response = Http::asForm()
                ->timeout((int) config('etsy.timeout', 15))
                ->withHeaders(['x-api-key' => $this->tokens->apiKey()])
                ->post((string) config('etsy.token_url'), [
                    'grant_type' => 'authorization_code',
                    'client_id' => (string) config('etsy.keystring'),
                    'redirect_uri' => $this->redirectUri(),
                    'code' => $code,
                    'code_verifier' => $verifier,
                ]);
        } catch (ConnectionException $exception) {
            throw EtsyException::unreachable(Str::limit($exception->getMessage(), 120));
        }

        if (! $response->successful() || ! is_string($response->json('access_token')) || ! is_string($response->json('refresh_token'))) {
            throw EtsyApiException::fromResponse($response);
        }

        /** @var array<string, mixed> $payload */
        $payload = $response->json();

        return $payload;
    }
}
