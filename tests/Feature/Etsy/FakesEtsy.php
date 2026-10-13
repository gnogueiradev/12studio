<?php

namespace Tests\Feature\Etsy;

use App\Models\EtsyConnection;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * A Etsy de faz de conta: liga a integracao a um dominio de teste e fecha a
 * porta a tudo o resto.
 *
 * O `preventStrayRequests` e o que interessa aqui. Um teste que se esqueca de
 * fingir um endpoint tem de rebentar, nao de ir bater a Etsy a serio com a
 * chave de quem o correu — e muito menos criar la um anuncio.
 */
trait FakesEtsy
{
    protected const ETSY_API = 'https://etsy.test/v3/application';

    protected const ETSY_TOKEN_URL = 'https://etsy.test/v3/public/oauth/token';

    protected function configureEtsy(bool $enabled = true): void
    {
        config([
            'etsy.enabled' => $enabled,
            'etsy.keystring' => 'chave-de-teste',
            'etsy.shared_secret' => 'segredo-de-teste',
            'etsy.redirect_uri' => 'https://12studio.test/admin/definicoes/etsy/callback',
            'etsy.api_url' => self::ETSY_API,
            'etsy.token_url' => self::ETSY_TOKEN_URL,
        ]);

        Http::preventStrayRequests();

        // Uma pagina Inertia pedida por inteiro tenta o servidor de SSR
        // (127.0.0.1:13714), e isso tambem e um pedido tresmalhado.
        config(['inertia.ssr.enabled' => false]);

        // O cliente espera entre tentativas; nos testes o relogio nao anda.
        Sleep::fake();
    }

    /**
     * Uma loja ja ligada, com o token de acesso ainda valido.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function connectEtsy(array $overrides = []): EtsyConnection
    {
        return EtsyConnection::query()->create([
            'shop_id' => 4242,
            'shop_name' => 'DozeStudio',
            'etsy_user_id' => 9001,
            'scopes' => implode(' ', (array) config('etsy.scopes')),
            'access_token' => '9001.acesso-valido',
            'refresh_token' => '9001.renovacao-valida',
            'access_expires_at' => now()->addHour(),
            'orders_since' => now(),
            ...$overrides,
        ]);
    }

    /**
     * Um anuncio como a Etsy o devolve (ShopListing), com inventario e fotos.
     * Um rascunho de duas variantes por omissao — e o que a loja tem hoje.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function etsyListing(array $overrides = []): array
    {
        $price = fn (int $cents): array => ['amount' => $cents, 'divisor' => 100, 'currency_code' => 'EUR'];

        return [
            'listing_id' => 7001,
            'shop_id' => 4242,
            'user_id' => 9001,
            'title' => 'Spiral Vase — 3D printed',
            'description' => "A vase printed in PLA.\nMade to order.",
            'state' => 'draft',
            'url' => 'https://www.etsy.com/listing/7001/spiral-vase',
            'tags' => ['vase', '3d printed'],
            'materials' => ['PLA'],
            'taxonomy_id' => 1234,
            'who_made' => 'i_did',
            'when_made' => 'made_to_order',
            'shipping_profile_id' => 555,
            'return_policy_id' => null,
            'shop_section_id' => 31,
            'quantity' => 8,
            'skus' => ['VASO-1', 'VASO-2'],
            'has_variations' => true,
            'price' => $price(2490),
            'num_favorers' => 3,
            'views' => 120,
            'images' => [
                ['listing_image_id' => 81, 'rank' => 1, 'url_570xN' => 'https://i.etsystatic.com/81.jpg', 'alt_text' => 'Vaso'],
                ['listing_image_id' => 82, 'rank' => 2, 'url_570xN' => 'https://i.etsystatic.com/82.jpg', 'alt_text' => null],
            ],
            'videos' => [],
            'inventory' => [
                'products' => [
                    [
                        'product_id' => 9101, 'sku' => 'VASO-1', 'is_deleted' => false,
                        'property_values' => [['property_id' => 513, 'property_name' => 'Cor', 'values' => ['Preto'], 'value_ids' => [1]]],
                        'offerings' => [['offering_id' => 9201, 'quantity' => 5, 'is_enabled' => true, 'price' => $price(2490)]],
                    ],
                    [
                        'product_id' => 9102, 'sku' => 'VASO-2', 'is_deleted' => false,
                        'property_values' => [['property_id' => 513, 'property_name' => 'Cor', 'values' => ['Branco'], 'value_ids' => [2]]],
                        'offerings' => [['offering_id' => 9202, 'quantity' => 3, 'is_enabled' => true, 'price' => $price(2690)]],
                    ],
                ],
                'price_on_property' => [513],
                'quantity_on_property' => [513],
                'sku_on_property' => [513],
            ],
            ...$overrides,
        ];
    }

    /**
     * Os pedidos feitos a Etsy cujo URL contem $needle, por ordem.
     *
     * @return Collection<int, Request>
     */
    protected function etsyRequests(string $needle = ''): Collection
    {
        return Http::recorded()
            ->map(fn (array $pair): Request => $pair[0])
            ->filter(fn (Request $request): bool => str_contains($request->url(), 'etsy.test')
                && ($needle === '' || str_contains($request->url(), $needle)))
            ->values();
    }
}
