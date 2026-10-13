<?php

namespace Tests\Feature\Etsy;

use App\Etsy\EtsyListingSync;
use App\Etsy\EtsySettings;
use App\Models\Color;
use App\Models\EtsyConnection;
use App\Models\EtsyListing;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Variant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
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

    /** @var array<string, mixed> As respostas trocadas do fakeEtsyListingWrites. */
    private array $etsyWriteOverrides = [];

    private bool $etsyWritesFaked = false;

    private int $etsyImageId = 8100;

    /** @var array<int, array<string, mixed>> Os recibos do fakeEtsyReceipts. */
    private array $etsyReceipts = [];

    /** @var array<string, mixed> */
    private array $etsyReceiptOverrides = [];

    private bool $etsyReceiptsFaked = false;

    /**
     * O estado em que o anuncio 7100 esta NA ETSY. Null = igual ao que o
     * 12studio julga (o caso normal); com valor, e o dono a ter mexido la a
     * mao, ou o anuncio a ter esgotado ou expirado sem nos sabermos.
     */
    private ?string $etsyRemoteState = null;

    /** Para o anuncio 7100 responder outra coisa (um 404, por exemplo). */
    private mixed $etsyRemoteListing = null;

    private bool $etsyRemoteListingFaked = false;

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
     * Um perfil de envio como a Etsy o devolve, com um destino (Portugal) e
     * um upgrade.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function etsyShippingProfile(array $overrides = []): array
    {
        $price = fn (int $cents): array => ['amount' => $cents, 'divisor' => 100, 'currency_code' => 'EUR'];

        return [
            'shipping_profile_id' => 555,
            'title' => 'CTT normal',
            'origin_country_iso' => 'PT',
            'origin_postal_code' => '4000-123',
            'shipping_profile_destinations' => [[
                'shipping_profile_destination_id' => 601,
                'shipping_profile_id' => 555,
                'destination_country_iso' => 'PT',
                'destination_region' => 'none',
                'primary_cost' => $price(450),
                'secondary_cost' => $price(100),
                'min_delivery_days' => 1,
                'max_delivery_days' => 3,
                'shipping_carrier_id' => null,
                'mail_class' => null,
            ]],
            'shipping_profile_upgrades' => [[
                'upgrade_id' => 701,
                'shipping_profile_id' => 555,
                'upgrade_name' => 'Expresso',
                'type' => 0,
                'price' => $price(300),
                'secondary_price' => $price(0),
                'min_delivery_days' => 1,
                'max_delivery_days' => 1,
            ]],
            ...$overrides,
        ];
    }

    /**
     * As leituras de configuracao da loja (perfis de envio e de
     * processamento, devolucoes, seccoes, transportadoras, a propria loja),
     * todas com uma resposta. As escritas ficam por fingir a quem as testa.
     *
     * @param  array<string, mixed>  $overrides  Por chave: profiles, processing, policies, sections, carriers, shop.
     */
    protected function fakeEtsyShopConfig(array $overrides = []): void
    {
        $list = fn (array $results): array => ['count' => count($results), 'results' => $results];

        $get = fn (array $body) => fn ($request) => $request->method() === 'GET' ? Http::response($body) : null;

        Http::fake([
            'etsy.test/v3/application/shops/4242/shipping-profiles' => $get($list($overrides['profiles'] ?? [$this->etsyShippingProfile()])),
            'etsy.test/v3/application/shops/4242/readiness-state-definitions*' => $get($list($overrides['processing'] ?? [
                ['readiness_state_id' => 801, 'readiness_state' => 'ready_to_ship', 'min_processing_time' => 1, 'max_processing_time' => 2, 'processing_time_unit' => 'days'],
                ['readiness_state_id' => 802, 'readiness_state' => 'made_to_order', 'min_processing_time' => 3, 'max_processing_time' => 5, 'processing_time_unit' => 'days'],
            ])),
            'etsy.test/v3/application/shops/4242/policies/return' => $get($list($overrides['policies'] ?? [
                ['return_policy_id' => 901, 'shop_id' => 4242, 'accepts_returns' => true, 'accepts_exchanges' => false, 'return_deadline' => 14],
            ])),
            'etsy.test/v3/application/shops/4242/sections' => $get($list($overrides['sections'] ?? [
                ['shop_section_id' => 31, 'title' => 'Vasos', 'rank' => 1, 'active_listing_count' => 4],
                ['shop_section_id' => 32, 'title' => 'Suportes', 'rank' => 2, 'active_listing_count' => 0],
            ])),
            'etsy.test/v3/application/shipping-carriers*' => $get($list($overrides['carriers'] ?? [
                ['shipping_carrier_id' => 12, 'name' => 'CTT', 'domestic_classes' => [['mail_class_key' => 'ctt_normal', 'name' => 'Correio Normal']], 'international_classes' => []],
            ])),
            'etsy.test/v3/application/shops/4242' => $get($overrides['shop'] ?? [
                'shop_id' => 4242,
                'shop_name' => 'DozeStudio',
                'title' => 'Peças impressas em 3D',
                'announcement' => 'Bem-vindos!',
                'sale_message' => 'Obrigado pela compra.',
                'digital_sale_message' => null,
                'policy_additional' => null,
                'currency_code' => 'EUR',
            ]),
        ]);

        // Enviar para um anuncio a venda importa primeiro as encomendas: sem
        // mais nada, a loja nao tem nenhuma.
        $this->fakeEtsyReceipts($this->etsyReceipts, $this->etsyReceiptOverrides);
    }

    /**
     * Um produto pronto a ir para a Etsy: duas variantes (Preto e Branco, no
     * mesmo material), em stock, com as predefinicoes da loja escolhidas.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function etsyReadyProduct(array $attributes = []): Product
    {
        $material = Material::factory()->create(['name' => 'PLA']);
        $product = Product::factory()->create([
            'name' => 'Vaso Espiral',
            'description' => '<p>Um vaso <strong>impresso</strong> em PLA.</p><ul><li>Feito no Porto</li><li>Leve</li></ul>',
            'fulfillment_mode' => 'in_stock',
            'vat_rate' => 23,
            ...$attributes,
        ]);

        Variant::factory()->for($product)->stock(5)->create([
            'sku' => 'VASO-P', 'price_cents' => 2490, 'is_default' => true, 'material_id' => $material->id,
            'color_id' => Color::factory()->create(['name' => 'Preto'])->id,
        ]);
        Variant::factory()->for($product)->stock(3)->create([
            'sku' => 'VASO-B', 'price_cents' => 2690, 'material_id' => $material->id,
            'color_id' => Color::factory()->create(['name' => 'Branco'])->id,
        ]);

        app(EtsySettings::class)->save([
            'default_shipping_profile_id' => 555,
            'ready_to_ship_profile_id' => 801,
            'made_to_order_profile_id' => 802,
            'default_taxonomy_id' => 1234,
        ]);

        return $product;
    }

    /**
     * Um produto cujo anuncio ja foi enviado para a Etsy (o 7100), com uma
     * fotografia la, e que esta la no estado pedido. Precisa do
     * Storage::fake('public'). Deixa a contagem dos pedidos a zero.
     */
    protected function etsyPushedProduct(string $state = 'active', ?Product $product = null): Product
    {
        $product ??= $this->etsyReadyProduct();
        $image = ProductImage::factory()->for($product)->create(['sort_order' => 1]);
        Storage::disk('public')->put($image->path, 'bytes-da-foto');

        $this->fakeEtsyListingWrites();
        app(EtsyListingSync::class)->push($product);
        EtsyListing::query()->where('product_id', $product->id)->update(['state' => $state]);
        $this->fakeEtsyListingWrites();
        $this->fakeEtsyRemoteListing();

        return $product;
    }

    /**
     * O anuncio 7100 como a Etsy o mostra a quem pergunta por ele. A
     * sincronizacao pergunta antes de decidir tirar ou repor um anuncio de
     * venda: o estado que ca esta pode ter horas.
     */
    protected function fakeEtsyRemoteListing(): void
    {
        if ($this->etsyRemoteListingFaked) {
            return;
        }

        $this->etsyRemoteListingFaked = true;

        Http::fake(['etsy.test/v3/application/listings/7100*' => function (Request $request) {
            if ($request->method() !== 'GET' || str_contains($request->url(), '/7100/')) {
                return null;
            }

            return $this->etsyRemoteListing ?? Http::response($this->etsyListing([
                'listing_id' => 7100,
                'state' => $this->etsyRemoteState
                    ?? EtsyListing::query()->where('etsy_listing_id', 7100)->value('state')
                    ?? 'active',
            ]));
        }]);
    }

    /**
     * As escritas de um envio de anuncio, todas com resposta: criar o
     * rascunho, atualiza-lo, o inventario (que devolve os ids que a Etsy deu
     * a cada variacao), fotografias, video e apagar.
     *
     * @param  array<string, mixed>  $overrides  Por chave: create, update, inventory, image, video, delete.
     */
    protected function fakeEtsyListingWrites(array $overrides = []): void
    {
        // Chamar outra vez troca as respostas e recomeca a contagem dos
        // pedidos (o fake() limpa o Http::recorded()), sem empilhar um
        // segundo conjunto de respostas atras do primeiro — que era o que
        // continuava a responder.
        $this->etsyWriteOverrides = $overrides;
        Http::fake([]);

        if ($this->etsyWritesFaked) {
            return;
        }

        $this->etsyWritesFaked = true;

        Http::fake(function (Request $request) {
            $url = $request->url();
            $method = $request->method();
            $overrides = $this->etsyWriteOverrides;

            if (! str_contains($url, 'etsy.test') || $method === 'GET') {
                return null;
            }

            return match (true) {
                $method === 'PUT' && str_contains($url, '/properties/') => Http::response(['property_id' => (int) basename($url)]),
                $method === 'POST' && str_ends_with($url, '/shops/4242/listings') => $overrides['create']
                    ?? Http::response(['listing_id' => 7100, 'state' => 'draft', 'url' => 'https://www.etsy.com/listing/7100']),
                // Mudar o estado (publicar, desativar) devolve o estado pedido.
                $method === 'PATCH' && preg_match('#/shops/4242/listings/\d+$#', $url) === 1 => $overrides['update']
                    ?? Http::response(['listing_id' => (int) basename($url), 'state' => $request->data()['state'] ?? 'draft']),
                $method === 'DELETE' && preg_match('#/application/listings/\d+$#', $url) === 1 => $overrides['delete']
                    ?? Http::response('', 204),
                $method === 'PUT' && str_contains($url, '/inventory') => $overrides['inventory']
                    ?? Http::response(['products' => array_map(fn (array $product, int $index): array => [
                        'product_id' => 9301 + $index,
                        'sku' => $product['sku'] ?? '',
                        'offerings' => [['offering_id' => 9401 + $index, ...($product['offerings'][0] ?? [])]],
                    ], (array) ($request->data()['products'] ?? []), array_keys((array) ($request->data()['products'] ?? [])))]),
                $method === 'POST' && str_ends_with($url, '/images') => $overrides['image']
                    ?? Http::response(['listing_image_id' => ++$this->etsyImageId]),
                $method === 'POST' && str_ends_with($url, '/videos') => $overrides['video']
                    ?? Http::response(['video_id' => 5001]),
                $method === 'DELETE' => Http::response('', 204),
                default => null,
            };
        });
    }

    /**
     * Um recibo como a Etsy o devolve (ShopReceipt): pago, por enviar, duas
     * unidades do VASO-P para Portugal. Traz o que a Etsy manda mesmo quando
     * nao o queremos (o email do comprador), para os testes provarem que nao
     * fica ca.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function etsyReceipt(array $overrides = []): array
    {
        $money = fn (int $cents): array => ['amount' => $cents, 'divisor' => 100, 'currency_code' => $overrides['currency'] ?? 'EUR'];
        $at = now()->timestamp;
        $receiptId = (int) ($overrides['receipt_id'] ?? 3001);

        unset($overrides['currency']);

        return [
            'receipt_id' => $receiptId,
            'receipt_type' => 0,
            'seller_user_id' => 9001,
            'seller_email' => 'loja@example.com',
            'buyer_user_id' => 77001,
            'buyer_email' => 'comprador@example.com',
            'name' => 'Ana Maria Silva',
            'first_line' => 'Rua das Flores 12',
            'second_line' => '2.º Esq',
            'city' => 'Porto',
            'state' => null,
            'zip' => '4000-123',
            'country_iso' => 'PT',
            'formatted_address' => "Ana Maria Silva\nRua das Flores 12\n2.º Esq\n4000-123 Porto\nPortugal",
            'status' => 'paid',
            'payment_method' => 'cc',
            'payment_email' => 'comprador@example.com',
            'message_from_buyer' => 'Pode embrulhar para oferta?',
            'message_from_seller' => null,
            'message_from_payment' => null,
            'is_paid' => true,
            'is_shipped' => false,
            'create_timestamp' => $at,
            'created_timestamp' => $at,
            'update_timestamp' => $at,
            'updated_timestamp' => $at,
            'is_gift' => false,
            'gift_message' => '',
            'gift_sender' => '',
            'grandtotal' => $money(5430),
            'subtotal' => $money(4980),
            'total_price' => $money(4980),
            'total_shipping_cost' => $money(450),
            'total_tax_cost' => $money(0),
            'total_vat_cost' => $money(0),
            'discount_amt' => $money(0),
            'gift_wrap_price' => $money(0),
            'shipments' => [],
            'refunds' => [],
            'transactions' => [[
                'transaction_id' => $receiptId + 1000,
                'title' => 'Vaso Espiral',
                'receipt_id' => $receiptId,
                'listing_id' => 7100,
                'product_id' => 9301,
                'sku' => 'VASO-P',
                'quantity' => 2,
                'price' => $money(2490),
                'shipping_cost' => $money(450),
                'is_digital' => false,
                'transaction_type' => 'listing',
                'paid_timestamp' => $at,
                'shipped_timestamp' => null,
                'variations' => [
                    ['property_id' => 513, 'value_id' => null, 'formatted_name' => 'Cor', 'formatted_value' => 'Preto'],
                ],
            ]],
            ...$overrides,
        ];
    }

    /**
     * Os recibos que a Etsy tem, e o pagamento de cada um. Chamar outra vez
     * troca os recibos (e recomeca a contagem dos pedidos) sem empilhar
     * respostas. O filtro `min_last_modified` e a paginacao funcionam como
     * la.
     *
     * @param  array<int, array<string, mixed>>  $receipts
     * @param  array<string, mixed>  $overrides  Por chave: list, payments.
     */
    protected function fakeEtsyReceipts(array $receipts = [], array $overrides = []): void
    {
        $this->etsyReceipts = $receipts;
        $this->etsyReceiptOverrides = $overrides;
        Http::fake([]);

        if ($this->etsyReceiptsFaked) {
            return;
        }

        $this->etsyReceiptsFaked = true;

        Http::fake(function (Request $request) {
            $url = $request->url();
            $path = (string) parse_url($url, PHP_URL_PATH);

            if (! str_contains($url, 'etsy.test') || $request->method() !== 'GET' || ! str_contains($path, '/shops/4242/receipts')) {
                return null;
            }

            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            if (preg_match('#/receipts/(\d+)/payments$#', $path, $match) === 1) {
                return $this->etsyReceiptOverrides['payments'] ?? Http::response(['count' => 1, 'results' => [[
                    'payment_id' => (int) $match[1] + 3000,
                    'receipt_id' => (int) $match[1],
                    'amount_gross' => ['amount' => 5430, 'divisor' => 100, 'currency_code' => 'EUR'],
                    'amount_fees' => ['amount' => 247, 'divisor' => 100, 'currency_code' => 'EUR'],
                    'amount_net' => ['amount' => 5183, 'divisor' => 100, 'currency_code' => 'EUR'],
                    'currency' => 'EUR',
                    'status' => 'settled',
                    'payment_adjustments' => [],
                ]]]);
            }

            if (preg_match('#/receipts/(\d+)/transactions$#', $path, $match) === 1) {
                $receipt = collect($this->etsyReceipts)->firstWhere('receipt_id', (int) $match[1]);
                $rows = (array) ($receipt['transactions'] ?? []);

                return Http::response(['count' => count($rows), 'results' => $rows]);
            }

            if (preg_match('#/receipts/(\d+)$#', $path, $match) === 1) {
                $receipt = collect($this->etsyReceipts)->firstWhere('receipt_id', (int) $match[1]);

                return $receipt === null ? Http::response(['error' => 'Receipt not found'], 404) : Http::response($receipt);
            }

            if (isset($this->etsyReceiptOverrides['list'])) {
                return $this->etsyReceiptOverrides['list'];
            }

            $since = (int) ($query['min_last_modified'] ?? 0);
            $matching = collect($this->etsyReceipts)
                ->filter(fn (array $receipt): bool => (int) $receipt['update_timestamp'] >= $since)
                ->sortBy('update_timestamp')
                ->values();

            return Http::response([
                'count' => $matching->count(),
                'results' => $matching->slice((int) ($query['offset'] ?? 0), (int) ($query['limit'] ?? 25))->values()->all(),
            ]);
        });
    }

    /**
     * O valor de um campo de um pedido multipart (os uploads).
     */
    protected function multipartField(Request $request, string $name): ?string
    {
        foreach ($request->data() as $part) {
            if (is_array($part) && ($part['name'] ?? null) === $name) {
                return (string) $part['contents'];
            }
        }

        return null;
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
