<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Store\Seo;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deixar de receber ofertas pelo link dos emails (App\Customers\RepeatOffers).
 *
 * O mesmo endereco assinado serve as duas coisas. O POST e a recusa de um
 * clique do RFC 8058: chega do fornecedor de email, sem sessao nem token de
 * CSRF (fica de fora do CSRF no bootstrap/app.php), e quem o guarda e a
 * assinatura. O GET e o link do rodape: so mostra o botao, porque os leitores
 * de email e os antivirus abrem os links sozinhos e recusavam pelo cliente.
 * Fora da porta das compras: com a loja desligada, recusar continua a
 * funcionar.
 */
class UnsubscribeController extends Controller
{
    public function show(Request $request, User $user): Response
    {
        return $this->page($request, $user->marketing_opt_out_at !== null);
    }

    public function store(Request $request, User $user): Response
    {
        // A data da primeira recusa fica: repetir nao a muda.
        if ($user->marketing_opt_out_at === null) {
            $user->forceFill(['marketing_opt_out_at' => now()])->save();
        }

        return $this->page($request, true);
    }

    /**
     * A pagina responde tambem ao POST: o fornecedor de email quer um 200, e o
     * botao da pagina ve logo que ficou feito, no mesmo endereco.
     */
    private function page(Request $request, bool $done): Response
    {
        $response = Inertia::render('store/offers-unsubscribe', [
            // O proprio endereco assinado, para o botao fazer o POST.
            'action' => $request->getRequestUri(),
            'done' => $done,
        ])->withViewData(['seo' => Seo::make(path: '/', title: trans('store.offers.unsubscribe_title'), index: false)])
            ->toResponse($request);

        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
