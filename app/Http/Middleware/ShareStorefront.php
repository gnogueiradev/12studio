<?php

namespace App\Http\Middleware;

use App\Models\Category;
use App\Services\StoreMenus;
use App\Services\StoreSettings;
use App\Services\ThemeSettings;
use App\Shop\CartPresenter;
use App\Shop\CartService;
use App\Shop\ShopGate;
use App\Support\LocalizedUrl;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * O que o StoreLayout mostra em todas as paginas da montra (alias
 * `store.shared`): menus, contactos, redes sociais, as categorias do rodape e
 * a faixa de aviso do topo.
 *
 * Numa so prop, `storefront`, e preguicosa: so e montada quando a pagina e
 * mesmo renderizada, ja com o idioma do SetStoreLocale em vigor. Nao vai para
 * o backoffice.
 */
class ShareStorefront
{
    /** Quantas categorias cabem na coluna do rodape. */
    private const FOOTER_CATEGORIES = 6;

    public function __construct(
        private StoreMenus $menus,
        private StoreSettings $store,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->share();

        return $next($request);
    }

    /** Tambem usado pelas paginas de erro (StoreErrorPage). */
    public function share(): void
    {
        Inertia::share('storefront', fn (): array => [
            'menus' => [
                'header' => $this->menus->resolved('header'),
                'footer' => $this->menus->resolved('footer'),
            ],
            'contact' => $this->contact(),
            'social' => $this->social(),
            'categories' => $this->categories(),
            'logo' => app(ThemeSettings::class)->logoUrl(),
            'announcement' => $this->store->announcement(),
            // Se este visitante pode comprar (o interruptor das compras) e
            // quantas pecas tem no carrinho: o cabecalho so mostra o carrinho
            // a quem pode usa-lo.
            'shop' => $shop = $this->shopOpen(),
            'cartCount' => $shop ? $this->cartCount() : 0,
            // O limite geral de pecas por linha: o seletor de quantidade
            // desenha-o quando a variante nao tem outro teto.
            'maxQty' => (int) config('shop.cart.max_qty'),
            // A hora a que a pagina abriu, encriptada: os formularios de
            // conversa devolvem-na, e um envio rapido demais e de um robo
            // (OpenConversationRequest).
            'form' => ['started' => Crypt::encryptString((string) now()->timestamp)],
            // O cliente com sessao (a guarda `customer`), so com o nome: e o
            // que o cabecalho precisa, e nada mais vai para todas as paginas.
            'customer' => $this->customer(),
        ]);
    }

    /**
     * Resolvido so quando a pagina renderiza (as paginas de erro nao passam
     * pelo ciclo normal). A porta das compras usa sempre a guarda da equipa.
     */
    private function shopOpen(): bool
    {
        return app(ShopGate::class)->allows(Auth::guard('web')->user());
    }

    /** @return array{name: string}|null */
    private function customer(): ?array
    {
        $customer = Auth::guard('customer')->user();

        return $customer === null ? null : ['name' => (string) $customer->name];
    }

    /** Sem cookie do carrinho nem se consulta a base de dados. */
    private function cartCount(): int
    {
        $token = request()->cookie((string) config('shop.cart.cookie'));

        if (! is_string($token) || $token === '') {
            return 0;
        }

        return CartPresenter::count(app(CartService::class)->find($token));
    }

    /**
     * So os campos preenchidos: a montra nao mostra rotulos sem valor.
     *
     * @return array<string, string|int>
     */
    private function contact(): array
    {
        $form = $this->store->toForm();

        return array_filter([
            'email' => $form['contact_email'],
            'phone' => $form['phone'],
            'address' => $form['address'],
            'hours' => $form['opening_hours'],
            'responseHours' => $form['response_hours'],
        ], fn (mixed $value): bool => $value !== '' && $value !== null);
    }

    /**
     * @return array<int, array{network: string, label: string, url: string}>
     */
    private function social(): array
    {
        $links = [];

        foreach ($this->store->toForm()['social'] as $network => $url) {
            if ($url !== '') {
                $links[] = [
                    'network' => $network,
                    'label' => StoreSettings::SOCIAL_NETWORKS[$network][0],
                    'url' => $url,
                ];
            }
        }

        return $links;
    }

    /**
     * @return array<int, array{label: string, href: string}>
     */
    private function categories(): array
    {
        return Category::query()
            ->where('status', 'visible')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->limit(self::FOOTER_CATEGORIES)
            ->with(Category::withTranslations())
            ->get(['id', 'name', 'slug'])
            ->map(fn (Category $category): array => [
                'label' => (string) $category->translated('name'),
                'href' => LocalizedUrl::path("/categoria/{$category->slug}"),
            ])
            ->all();
    }
}
