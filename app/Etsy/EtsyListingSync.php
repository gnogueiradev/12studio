<?php

namespace App\Etsy;

use App\Models\EtsyConnection;
use App\Models\EtsyListing;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * O catalogo do 12studio e os anuncios da Etsy, postos lado a lado.
 *
 * Nesta fase so le e liga: ve o que a loja tem la e diz "este anuncio e este
 * produto". Nada aqui escreve na Etsy — os envios chegam nas fases seguintes,
 * em cima destas ligacoes.
 */
class EtsyListingSync
{
    /** O que se pede junto com um anuncio para o conseguir ligar. */
    public const DETAIL_INCLUDES = ['Images', 'Inventory', 'Videos'];

    public function __construct(
        private EtsyApi $api,
        private EtsyTokens $tokens,
        private EtsyListingMatcher $matcher,
    ) {}

    /**
     * Os anuncios da loja num estado, cada um com o produto a que esta
     * ligado ou, nao estando, com o palpite de qual sera.
     *
     * @return array{count: int, rows: array<int, array{listing: array<array-key, mixed>, linked: Product|null, suggested: Product|null}>}
     */
    public function remote(string $state, int $limit, int $offset): array
    {
        $response = $this->api->shopListings($this->connection()->shop_id, [
            'state' => $state,
            'limit' => $limit,
            'offset' => $offset,
            'includes' => ['Images'],
        ]);

        $listings = EtsyPresenter::rows($response['results'] ?? null);
        $ids = array_values(array_filter(array_column($listings, 'listing_id'), 'is_int'));

        $linked = EtsyListing::query()
            ->whereIn('etsy_listing_id', $ids)
            ->with('product')
            ->get()
            ->keyBy('etsy_listing_id');

        $suggested = $this->matcher->suggest(array_values(array_filter(
            $listings,
            fn (array $listing): bool => ! $linked->has($listing['listing_id'] ?? 0),
        )));

        return [
            'count' => (int) ($response['count'] ?? count($listings)),
            'rows' => array_map(fn (array $listing): array => [
                'listing' => $listing,
                'linked' => $linked->get($listing['listing_id'] ?? 0)?->product,
                'suggested' => $suggested[$listing['listing_id'] ?? 0] ?? null,
            ], $listings),
        ];
    }

    /**
     * Um anuncio da loja ligada, com fotos e inventario. Recusa os de outras
     * lojas: so se liga o que e nosso.
     *
     * @return array<array-key, mixed>
     */
    public function fetchOwn(int $etsyListingId): array
    {
        $connection = $this->connection();
        $remote = $this->api->listing($etsyListingId, self::DETAIL_INCLUDES);

        if ((int) ($remote['shop_id'] ?? 0) !== $connection->shop_id) {
            throw new EtsyException("O anúncio {$etsyListingId} não é da loja {$connection->shop_name}: só se ligam anúncios da loja ligada.");
        }

        return $remote;
    }

    /**
     * Liga um produto a um anuncio que ja existe na Etsy.
     *
     * Nao escreve nada la. Traz para ca o que o anuncio diz — titulo,
     * descricao, etiquetas, categoria, perfis — como conteudo Etsy do
     * produto: foi escrito a mao pelo dono, e sem isto o primeiro envio
     * substituia-o pelo texto do site. Os PRECOS nao se adotam: o preco e do
     * 12studio, e o que la esta aparece como diferenca antes de enviar.
     */
    public function link(Product $product, int $etsyListingId): EtsyListing
    {
        // A chamada a Etsy fica fora da transacao, como todo o I/O externo.
        return $this->adopt($product, $this->fetchOwn($etsyListingId));
    }

    /**
     * @param  array<array-key, mixed>  $remote  O anuncio, como veio do fetchOwn().
     */
    public function adopt(Product $product, array $remote): EtsyListing
    {
        $etsyListingId = (int) ($remote['listing_id'] ?? 0);

        $taken = EtsyListing::query()
            ->where('etsy_listing_id', $etsyListingId)
            ->where('product_id', '!=', $product->getKey())
            ->with('product')
            ->first();

        if ($taken !== null) {
            throw new EtsyException("O anúncio {$etsyListingId} já está ligado a \"{$taken->product->name}\". Desliga-o primeiro.");
        }

        return DB::transaction(function () use ($product, $remote, $etsyListingId): EtsyListing {
            $listing = EtsyListing::query()->firstOrNew(['product_id' => $product->getKey()]);

            // Religar a outro anuncio e comecar de novo: o que era do antigo
            // (ids de variantes e de imagens la) nao serve o novo.
            if ($listing->exists) {
                $listing->variants()->delete();
                $listing->images()->delete();
            }

            $listing->fill([
                'etsy_listing_id' => $etsyListingId,
                'state' => $this->string($remote['state'] ?? null, 20),
                'url' => $this->string($remote['url'] ?? null, 500),
                'title' => $this->string($remote['title'] ?? null, 255),
                'description' => is_string($remote['description'] ?? null) ? $remote['description'] : null,
                'tags' => $this->strings($remote['tags'] ?? null),
                'materials' => $this->strings($remote['materials'] ?? null),
                'taxonomy_id' => $this->id($remote['taxonomy_id'] ?? null),
                'who_made' => $this->string($remote['who_made'] ?? null, 20),
                'when_made' => $this->string($remote['when_made'] ?? null, 20),
                'shipping_profile_id' => $this->id($remote['shipping_profile_id'] ?? null),
                'return_policy_id' => $this->id($remote['return_policy_id'] ?? null),
                'shop_section_id' => $this->id($remote['shop_section_id'] ?? null),
                'remote_checked_at' => now(),
                // Ainda nada foi enviado daqui para este anuncio.
                'last_pushed_at' => null,
                'last_push_hash' => null,
                'last_error' => null,
                'auto_deactivated_at' => null,
            ]);
            $listing->save();

            $this->adoptVariants($listing, $product, $remote);
            $this->adoptImages($listing, $remote);

            return $listing;
        });
    }

    /**
     * Desfaz a ligacao. O anuncio fica na Etsy tal como esta.
     */
    public function unlink(Product $product): void
    {
        $listing = EtsyListing::query()->where('product_id', $product->getKey())->first();

        if ($listing === null) {
            return;
        }

        DB::transaction(function () use ($listing): void {
            $listing->variants()->delete();
            $listing->images()->delete();
            $listing->delete();
        });
    }

    /**
     * Que referencias casaram, e quais ficaram so de um dos lados — para
     * quem liga saber o que ainda tem de acertar.
     *
     * @param  array<array-key, mixed>  $remote
     * @return array{matched: array<int, string>, only_on_etsy: array<int, string>, only_here: array<int, string>}
     */
    public function skuReport(Product $product, array $remote): array
    {
        $there = [];

        foreach ($this->remoteProducts($remote) as $remoteProduct) {
            if (is_string($remoteProduct['sku'] ?? null) && trim($remoteProduct['sku']) !== '') {
                $there[Str::lower(trim($remoteProduct['sku']))] = trim($remoteProduct['sku']);
            }
        }

        $here = [];

        foreach ($product->variants()->pluck('sku') as $sku) {
            $here[Str::lower((string) $sku)] = (string) $sku;
        }

        return [
            'matched' => array_values(array_intersect_key($here, $there)),
            'only_on_etsy' => array_values(array_diff_key($there, $here)),
            'only_here' => array_values(array_diff_key($here, $there)),
        ];
    }

    private function connection(): EtsyConnection
    {
        return $this->tokens->connection() ?? throw EtsyException::notConnected();
    }

    /**
     * Cada variante do produto fica com o "product" e a "offering" que a
     * Etsy deu a mesma referencia. Sem olhar a maiusculas: la o SKU fica como
     * foi escrito a mao.
     *
     * @param  array<array-key, mixed>  $remote
     */
    private function adoptVariants(EtsyListing $listing, Product $product, array $remote): void
    {
        $bySku = [];

        foreach ($this->remoteProducts($remote) as $remoteProduct) {
            if (is_string($remoteProduct['sku'] ?? null) && trim($remoteProduct['sku']) !== '') {
                $bySku[Str::lower(trim($remoteProduct['sku']))] = $remoteProduct;
            }
        }

        foreach ($product->variants()->get(['id', 'sku']) as $variant) {
            $remoteProduct = $bySku[Str::lower($variant->sku)] ?? null;

            if ($remoteProduct === null) {
                continue;
            }

            $offering = EtsyPresenter::rows($remoteProduct['offerings'] ?? null)[0] ?? [];

            $listing->variants()->create([
                'variant_id' => $variant->id,
                'etsy_product_id' => $this->id($remoteProduct['product_id'] ?? null),
                'etsy_offering_id' => $this->id($offering['offering_id'] ?? null),
            ]);
        }
    }

    /**
     * As imagens que ja la estao ficam registadas sem fotografia de ca
     * (`product_image_id` a null): nao ha como saber de qual vieram, mas tem
     * de se saber que existem para o primeiro envio nao as duplicar.
     *
     * @param  array<array-key, mixed>  $remote
     */
    private function adoptImages(EtsyListing $listing, array $remote): void
    {
        foreach (EtsyPresenter::rows($remote['images'] ?? null) as $image) {
            $imageId = $this->id($image['listing_image_id'] ?? null);

            if ($imageId === null) {
                continue;
            }

            $listing->images()->create([
                'etsy_image_id' => $imageId,
                'rank' => is_numeric($image['rank'] ?? null) ? min(255, max(0, (int) $image['rank'])) : null,
            ]);
        }
    }

    /**
     * @param  array<array-key, mixed>  $remote
     * @return array<int, array<array-key, mixed>>
     */
    private function remoteProducts(array $remote): array
    {
        $inventory = $remote['inventory'] ?? null;

        return array_values(array_filter(
            EtsyPresenter::rows(is_array($inventory) ? ($inventory['products'] ?? null) : null),
            fn (array $product): bool => ! ($product['is_deleted'] ?? false),
        ));
    }

    private function id(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function string(mixed $value, int $max): ?string
    {
        return is_string($value) && $value !== '' ? Str::limit($value, $max, '') : null;
    }

    /**
     * @return array<int, string>|null
     */
    private function strings(mixed $values): ?array
    {
        return is_array($values) ? array_values(array_filter($values, 'is_string')) : null;
    }
}
