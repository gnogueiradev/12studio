<?php

namespace App\Http\Middleware;

use App\Services\SettingService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    public function __construct(
        private SettingService $settings,
    ) {}

    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                // Campos escolhidos a mao, e nao o modelo inteiro.
                //
                // Isto e partilhado em TODAS as paginas: vai no data-page do
                // HTML inicial e em cada resposta do Inertia. O #[Hidden] do
                // User tapa a password e os segredos do 2FA, mas nao o phone, o
                // nif, o admin_note nem o is_admin — o NIF e dado pessoal, e a
                // nota interna e, por definicao, o que a loja escreve sobre
                // alguem para nao lho mostrar.
                //
                // A lista abaixo e exatamente o que o frontend le (procura por
                // `auth.user` em resources/js). Um campo novo aqui e uma
                // decisao, nao um efeito lateral de acrescentar uma coluna.
                'user' => $this->sharedUser($request),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            // Moeda em vigor: o resources/js/lib/money.ts formata por ela em
            // vez do EUR fixo que tinha antes.
            'currency' => $this->settings->currency(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function sharedUser(Request $request): ?array
    {
        $user = $request->user();

        if ($user === null) {
            return null;
        }

        return [
            'id' => $user->getKey(),
            'name' => $user->name,
            'email' => $user->email,
            // Lido pelo settings/profile.tsx para mostrar o aviso de "verifica
            // o teu email".
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),
        ];
    }
}
