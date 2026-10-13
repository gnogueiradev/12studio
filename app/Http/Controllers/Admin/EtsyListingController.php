<?php

namespace App\Http\Controllers\Admin;

use App\Etsy\EtsyException;
use App\Etsy\EtsyListingSync;
use App\Etsy\EtsyPresenter;
use App\Etsy\EtsyTokens;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdminActionRequest;
use App\Http\Requests\Etsy\LinkEtsyListingRequest;
use App\Models\EtsyListing;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Etsy -> Anuncios: o que a loja tem na Etsy, e a que produto do 12studio
 * pertence cada anuncio.
 *
 * A pagina abre sempre, mesmo com a Etsy em baixo ou sem loja ligada: nesses
 * casos mostra porque, em vez de um erro 500 a meio do backoffice.
 */
class EtsyListingController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(
        private EtsyTokens $tokens,
        private EtsyListingSync $sync,
    ) {}

    public function index(Request $request): Response
    {
        $state = (string) $request->query('state', '');
        $page = max(1, (int) $request->query('page', '1'));
        $asked = in_array($state, EtsyListing::STATES, true);

        $connected = $this->tokens->isConnected();
        $error = null;
        $remote = ['count' => 0, 'rows' => []];

        if ($connected) {
            try {
                $state = $asked ? $state : 'active';
                $remote = $this->sync->remote($state, self::PER_PAGE, ($page - 1) * self::PER_PAGE);

                // Sem estado pedido e sem nada ativo, mostram-se os
                // rascunhos: numa loja que ainda nao publicou e la que esta
                // tudo, e abrir numa aba vazia parecia loja vazia.
                if (! $asked && $remote['count'] === 0) {
                    $state = 'draft';
                    $remote = $this->sync->remote($state, self::PER_PAGE, 0);
                }
            } catch (EtsyException $exception) {
                $error = $exception->getMessage();
            }
        }

        return Inertia::render('admin/etsy/anuncios', [
            'connected' => $connected,
            'error' => $error,
            'listings' => array_map(fn (array $row): array => [
                'listingId' => $row['listing']['listing_id'] ?? null,
                'title' => (string) ($row['listing']['title'] ?? ''),
                'state' => (string) ($row['listing']['state'] ?? ''),
                'url' => $row['listing']['url'] ?? null,
                'priceCents' => EtsyPresenter::cents($row['listing']['price'] ?? null),
                'currency' => EtsyPresenter::currency($row['listing']['price'] ?? null),
                'quantity' => $row['listing']['quantity'] ?? null,
                'skus' => array_values(array_filter((array) ($row['listing']['skus'] ?? []), 'is_string')),
                'imageUrl' => EtsyPresenter::imageUrl($row['listing']),
                'linkedProduct' => $this->reference($row['linked']),
                'suggestedProduct' => $this->reference($row['suggested']),
            ], $remote['rows']),
            'total' => $remote['count'],
            'perPage' => self::PER_PAGE,
            'filters' => ['state' => $state === '' ? 'active' : $state, 'page' => $page],
            'products' => Product::query()
                ->whereNotIn('id', EtsyListing::query()->whereNotNull('etsy_listing_id')->select('product_id'))
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Product $product): array => ['id' => $product->id, 'name' => $product->name])
                ->all(),
        ]);
    }

    public function link(LinkEtsyListingRequest $request, Product $product): RedirectResponse
    {
        try {
            $listing = $this->sync->link($product, (int) $request->validated('listing_id'));

            $matched = $listing->variants()->count();

            $this->toast(sprintf(
                '"%s" ligado ao anúncio. %s. Nada mudou na Etsy.',
                $product->name,
                match ($matched) {
                    0 => 'Nenhuma variante casou pela referência',
                    1 => '1 variante casou pela referência',
                    default => "{$matched} variantes casaram pela referência",
                },
            ));
        } catch (EtsyException $exception) {
            $this->toast($exception->getMessage(), 'error');
        }

        return back();
    }

    public function unlink(AdminActionRequest $request, Product $product): RedirectResponse
    {
        $this->sync->unlink($product);

        $this->toast("\"{$product->name}\" desligado. O anúncio continua na Etsy tal como está.", 'info');

        return back();
    }

    /**
     * @return array{id: int, name: string}|null
     */
    private function reference(?Product $product): ?array
    {
        return $product === null ? null : ['id' => $product->id, 'name' => $product->name];
    }
}
