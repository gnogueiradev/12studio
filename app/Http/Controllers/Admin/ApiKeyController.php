<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminActionRequest;
use App\Http\Requests\ApiKey\StoreApiKeyRequest;
use App\Models\User;
use App\Services\ApiKeyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Chaves de API para ligar o Claude (e outros clientes MCP) ao backoffice.
 *
 * Cada acao pede a password outra vez (password.confirm). O 2FA nao e
 * exigido — decisao do dono. O que segura uma chave exposta e o resto: o
 * email a cada chave criada, a validade curta e o "Revogar tudo".
 */
class ApiKeyController extends Controller
{
    public function __construct(
        private ApiKeyService $keys,
    ) {}

    public function index(Request $request): Response
    {
        $user = $this->admin($request);

        return Inertia::render('admin/definicoes/chaves-api', [
            'keys' => $this->keys->list($user),
            'lifetimes' => ApiKeyService::LIFETIMES,
            'mcpUrl' => url('/mcp'),
            'enabled' => (bool) config('mcp.enabled'),
            // So existe no pedido a seguir a criacao: o token em claro nunca
            // volta a ser mostrado.
            'createdToken' => $request->session()->get('api_key_token'),
        ]);
    }

    public function store(StoreApiKeyRequest $request): RedirectResponse
    {
        $user = $this->admin($request);

        /** @var array{name: string, access: string, days: int} $data */
        $data = $request->validated();

        $created = $this->keys->create($user, $data['name'], $data['access'], (int) $data['days']);

        // Flash de sessao e nao prop da resposta: o PRG do Inertia levaria o
        // token para o historico do browser se fosse no URL.
        $request->session()->flash('api_key_token', $created['token']);

        $this->toast('Chave criada. Copia-a agora — não volta a aparecer.');

        return to_route('admin.chaves-api.index');
    }

    public function destroy(AdminActionRequest $request, string $key): RedirectResponse
    {
        $revoked = $this->keys->revoke($this->admin($request), $key);

        $this->toast($revoked ? 'Chave revogada.' : 'Chave não encontrada.', $revoked ? 'success' : 'error');

        return back();
    }

    public function destroyAll(AdminActionRequest $request): RedirectResponse
    {
        $count = $this->keys->revokeAll($this->admin($request));

        $this->toast($count === 1 ? '1 chave revogada.' : "{$count} chaves revogadas.");

        return back();
    }

    private function admin(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->isAdmin(), 403);

        return $user;
    }
}
