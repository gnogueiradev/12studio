<?php

namespace App\Http\Middleware;

use App\Models\Category;
use App\Services\StoreMenus;
use App\Services\StoreSettings;
use App\Services\ThemeSettings;
use App\Support\LocalizedUrl;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * O que o StoreLayout mostra em todas as paginas da montra (alias
 * `store.shared`): menus, contactos, redes sociais e as categorias do rodape.
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
            // A hora a que a pagina abriu, encriptada: os formularios de
            // conversa devolvem-na, e um envio rapido demais e de um robo
            // (OpenConversationRequest).
            'form' => ['started' => Crypt::encryptString((string) now()->timestamp)],
        ]);
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
