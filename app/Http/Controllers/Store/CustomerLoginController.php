<?php

namespace App\Http\Controllers\Store;

use App\Customers\CustomerLoginLinks;
use App\Http\Controllers\Controller;
use App\Store\Seo;
use App\Support\LocalizedUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Entrar na conta de cliente com um link no email (guarda `customer`).
 *
 * O GET do link so mostra um botao: os leitores de email e os antivirus abrem
 * os links sozinhos, e um GET que entrasse gastava o link antes do cliente.
 * Quem entra e o POST desse botao. Tudo aqui sai sem indexar e sem Referer.
 */
class CustomerLoginController extends Controller
{
    public function __construct(
        private CustomerLoginLinks $links,
    ) {}

    public function show(Request $request): Response
    {
        return $this->private(Inertia::render('store/account/login')
            ->withViewData(['seo' => Seo::make(path: '/entrar', title: trans('store.account.login_title'), index: false)])
            ->toResponse($request));
    }

    /** Responde sempre o mesmo, haja conta com esse email ou nao. */
    public function send(Request $request): Response
    {
        $data = $request->validate(['email' => ['required', 'string', 'email:rfc', 'max:255']], [], [
            'email' => trans('store.attributes.email'),
        ]);

        $this->links->send($data['email']);
        $this->toast(trans('store.account.sent'));

        // Caminho fixo e nao `back()`: a pagina nao manda Referer, e o
        // Referer nao decide para onde a loja manda o cliente.
        return $this->private(redirect(LocalizedUrl::path('/entrar')));
    }

    /** Mostra para que email e o link, sem o gastar; o botao faz o POST. */
    public function confirm(Request $request, string $token): Response
    {
        $email = $this->links->pendingEmail($token);

        // O caminho do SEO (canonical, hreflang) fica sem o segredo.
        return $this->private(Inertia::render('store/account/confirm', [
            'token' => $token,
            'email' => $email === null ? null : $this->mask($email),
        ])->withViewData(['seo' => Seo::make(path: '/entrar', title: trans('store.account.login_title'), index: false)])
            ->toResponse($request));
    }

    public function enter(Request $request, string $token): Response
    {
        $user = $this->links->enter($token);

        if ($user === null) {
            $this->toast(trans('store.account.link_invalid'), 'error');

            return $this->private(redirect(LocalizedUrl::path('/entrar')));
        }

        Auth::guard('customer')->login($user, remember: true);
        $request->session()->regenerate();

        return $this->private(redirect(LocalizedUrl::path('/conta')));
    }

    /**
     * Sai so da guarda `customer`. Sem invalidate(): a sessao e a mesma da
     * equipa (guarda `web`) e do carrinho, e quem testa uma conta de cliente
     * no browser do backoffice continua dentro do backoffice.
     */
    public function logout(Request $request): Response
    {
        Auth::guard('customer')->logout();
        $request->session()->regenerateToken();

        return $this->private(redirect(LocalizedUrl::path('/')));
    }

    /** "ana@exemplo.pt" fica "a***@exemplo.pt". */
    private function mask(string $email): string
    {
        return Str::substr($email, 0, 1).'***@'.Str::after($email, '@');
    }

    private function private(Response $response): Response
    {
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
