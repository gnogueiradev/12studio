<?php

namespace App\Http\Controllers\Admin;

use App\Alerts\SecurityAlerts;
use App\Etsy\EtsyApi;
use App\Etsy\EtsyException;
use App\Etsy\EtsyOAuth;
use App\Etsy\EtsyTokens;
use App\Etsy\EtsyWebhookSignature;
use App\Http\Controllers\Controller;
use App\Http\Controllers\EtsyWebhookController;
use App\Http\Requests\AdminActionRequest;
use App\Models\EtsyConnection;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Definicoes -> Etsy: ligar a loja, ver o estado da ligacao, desligar.
 *
 * Admins, com a password pedida outra vez (como as chaves de API e os
 * webhooks): quem liga uma loja escolhe para onde vao os produtos e de onde
 * entram encomendas. Os tokens nunca chegam ao browser — a pagina so recebe o
 * nome da loja e o estado.
 */
class EtsySettingsController extends Controller
{
    public function __construct(
        private EtsyTokens $tokens,
        private EtsyOAuth $oauth,
        private EtsyApi $api,
        private SecurityAlerts $alerts,
    ) {}

    public function index(): Response
    {
        $connection = $this->tokens->connection();

        return Inertia::render('admin/definicoes/etsy', [
            'enabled' => $this->tokens->isEnabled(),
            'configured' => $this->tokens->isConfigured(),
            'redirectUri' => $this->oauth->redirectUri(),
            'requiredScopes' => array_values((array) config('etsy.scopes')),
            'connection' => $connection === null ? null : $this->present($connection),
            // Os avisos da Etsy (opcional): o endereco a registar no portal
            // de webhooks da app, e se o segredo ja esta no .env.
            'webhook' => [
                'url' => route('webhooks.etsy'),
                'configured' => app(EtsyWebhookSignature::class)->isConfigured(),
                'events' => EtsyWebhookController::EVENTS,
            ],
        ]);
    }

    /**
     * A ida. E um GET com redirect para fora (etsy.com): o ecra chega aqui
     * por uma ligacao normal, nao por um pedido do Inertia.
     */
    public function connect(AdminActionRequest $request): RedirectResponse
    {
        try {
            return redirect()->away($this->oauth->authorizationUrl($request));
        } catch (EtsyException $exception) {
            $this->toast($exception->getMessage(), 'error');

            return to_route('admin.etsy.index');
        }
    }

    /**
     * O regresso. Fora do `password.confirm` de proposito: entre a ida e a
     * volta o dono esteve na Etsy, e o que prova que este regresso e dele e o
     * `state` guardado na sessao — nao voltar a escrever a password.
     */
    public function callback(AdminActionRequest $request): RedirectResponse
    {
        $reconnecting = $this->tokens->isConnected();

        try {
            $connection = $this->oauth->complete($request, $this->user($request));
        } catch (EtsyException $exception) {
            $this->toast($exception->getMessage(), 'error');

            return to_route('admin.etsy.index');
        }

        $this->alerts->etsyConnection($reconnecting ? 'religada' : 'ligada', $connection->shop_name, $this->user($request));
        $this->toast("Loja {$connection->shop_name} ligada.");

        return to_route('admin.etsy.index');
    }

    public function disconnect(AdminActionRequest $request): RedirectResponse
    {
        $connection = $this->tokens->connection();

        if ($connection !== null) {
            $this->tokens->forget();
            $this->alerts->etsyConnection('desligada', $connection->shop_name, $this->user($request));
        }

        $this->toast('Loja desligada. Os anúncios que já estão na Etsy ficam como estão.', 'info');

        return back();
    }

    /**
     * Pergunta a Etsy quem somos. Sem fila: quem carrega em "Testar" quer
     * saber ja, e quer ler o que a Etsy respondeu se correr mal.
     */
    public function test(AdminActionRequest $request): RedirectResponse
    {
        try {
            $me = $this->api->me();

            $this->toast('A Etsy respondeu: ligação a funcionar (loja #'.($me['shop_id'] ?? '?').').');
        } catch (EtsyException $exception) {
            $this->toast($exception->getMessage(), 'error');
        }

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(EtsyConnection $connection): array
    {
        return [
            'shopId' => $connection->shop_id,
            'shopName' => $connection->shop_name,
            'etsyUserId' => $connection->etsy_user_id,
            'scopes' => $connection->scopeList(),
            'missingScopes' => $connection->missingScopes(),
            'connectedAt' => $connection->created_at?->format('Y-m-d H:i'),
            'ordersSince' => $connection->orders_since?->format('Y-m-d H:i'),
            'broken' => $connection->isBroken(),
            'lastError' => $connection->last_error,
        ];
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
