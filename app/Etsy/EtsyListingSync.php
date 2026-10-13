<?php

namespace App\Etsy;

use App\Jobs\PruneEtsyListingImages;
use App\Jobs\UploadEtsyListingImage;
use App\Jobs\UploadEtsyListingVideo;
use App\Models\EtsyConnection;
use App\Models\EtsyListing;
use App\Models\EtsyListingImage;
use App\Models\EtsyVariant;
use App\Models\Product;
use App\Support\Money;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * O catalogo do 12studio e os anuncios da Etsy, postos lado a lado.
 *
 * Tres trabalhos:
 *
 *   ligar    dizer "este anuncio que ja la esta e este produto" (nao escreve
 *            nada na Etsy);
 *   guardar  o conteudo proprio da Etsy de um produto — titulo, categoria,
 *            precos — fica ca ate ser enviado;
 *   enviar   criar ou atualizar o anuncio la: o rascunho, o inventario, os
 *            atributos, as fotografias e o video.
 *
 * O 12studio e quem manda no catalogo: o que se envia e o que o
 * EtsyListingBuilder diz, e o que la estiver diferente e substituido.
 */
class EtsyListingSync
{
    /** O que se pede junto com um anuncio para o conseguir ligar ou comparar. */
    public const DETAIL_INCLUDES = ['Images', 'Inventory', 'Videos'];

    /** Os campos de conteudo que um produto pode ter proprios para a Etsy. */
    public const CONTENT_FIELDS = [
        'title', 'description', 'tags', 'materials', 'taxonomy_id', 'who_made',
        'when_made', 'shipping_profile_id', 'return_policy_id',
        'readiness_state_id', 'shop_section_id', 'properties',
    ];

    public function __construct(
        private EtsyApi $api,
        private EtsyTokens $tokens,
        private EtsyListingMatcher $matcher,
        private EtsyListingBuilder $builder,
    ) {}

    // ── Ler e ligar ────────────────────────────────────────────────────────

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

            $where = [
                'state' => $this->string($remote['state'] ?? null, 20),
                'url' => $this->string($remote['url'] ?? null, 500),
                'remote_checked_at' => now(),
            ];

            // Voltar a ligar o MESMO anuncio so o atualiza: o conteudo
            // trabalhado ca e os precos proprios ficam como estao.
            if ($listing->exists && $listing->etsy_listing_id === $etsyListingId) {
                $listing->fill($where)->save();
                $this->adoptVariants($listing, $product, $remote);

                return $listing;
            }

            // Outro anuncio (ou o primeiro): comeca-se de novo. O que era do
            // antigo — ids, mapa de propriedades, atributos, o video que la
            // estava, os precos proprios — nao serve o novo.
            if ($listing->exists) {
                $listing->variants()->delete();
                $listing->images()->delete();
            }

            $listing->fill([
                ...$where,
                'etsy_listing_id' => $etsyListingId,
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
                'readiness_state_id' => null,
                'properties' => null,
                'property_map' => null,
                'etsy_video_id' => null,
                'sync_enabled' => true,
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
     * quem liga saber o que ainda tem de acertar. `ambiguous` sao as que a
     * Etsy tem em mais de uma variacao: nao se adivinha qual e a nossa.
     *
     * @param  array<array-key, mixed>  $remote
     * @return array{matched: array<int, string>, ambiguous: array<int, string>, only_on_etsy: array<int, string>, only_here: array<int, string>}
     */
    public function skuReport(Product $product, array $remote): array
    {
        [$unique, $ambiguous] = $this->remoteSkus($remote);

        $there = [];

        foreach ($unique as $key => $remoteProduct) {
            $there[$key] = trim((string) $remoteProduct['sku']);
        }

        $here = [];

        foreach ($product->variants()->pluck('sku') as $sku) {
            $here[Str::lower((string) $sku)] = (string) $sku;
        }

        return [
            'matched' => array_values(array_intersect_key($here, $there)),
            'ambiguous' => array_values(array_intersect_key($here, $ambiguous)),
            'only_on_etsy' => array_values(array_diff_key($there, $here)),
            'only_here' => array_values(array_diff_key($here, $there, $ambiguous)),
        ];
    }

    // ── Guardar o conteudo Etsy de um produto ──────────────────────────────

    /**
     * Guarda ca o que o produto diz de proprio na Etsy. Nao envia nada.
     *
     * So mexe nas chaves que vierem em $data; um valor vazio tira o conteudo
     * proprio e volta a valer o do produto (ou a predefinicao).
     *
     * @param  array<string, mixed>  $data  Ja validado pelo SaveEtsyListingRequest.
     */
    public function saveContent(Product $product, array $data): EtsyListing
    {
        return DB::transaction(function () use ($product, $data): EtsyListing {
            $listing = EtsyListing::query()->firstOrNew(['product_id' => $product->getKey()]);

            foreach (self::CONTENT_FIELDS as $field) {
                if (array_key_exists($field, $data)) {
                    $listing->setAttribute($field, $this->blankToNull($data[$field]));
                }
            }

            if (array_key_exists('sync_enabled', $data)) {
                $listing->sync_enabled = (bool) $data['sync_enabled'];
            }

            $listing->save();

            foreach ((array) ($data['variants'] ?? []) as $row) {
                $changes = ['listing_id' => $listing->id];

                if (array_key_exists('etsy_price', $row)) {
                    $changes['etsy_price_cents'] = filled($row['etsy_price']) ? Money::fromDecimal((string) $row['etsy_price']) : null;
                }

                if (array_key_exists('enabled', $row) && $row['enabled'] !== null) {
                    $changes['enabled'] = (bool) $row['enabled'];
                }

                EtsyVariant::query()->updateOrCreate(['variant_id' => (int) $row['variant_id']], $changes);
            }

            if (array_key_exists('excluded_image_ids', $data)) {
                $excluded = array_map('intval', (array) $data['excluded_image_ids']);

                foreach ($product->images()->pluck('id') as $imageId) {
                    $row = $listing->images()->firstOrNew(['product_image_id' => $imageId]);
                    $exclude = in_array((int) $imageId, $excluded, true);

                    // So nasce uma linha para dizer "esta nao vai"; as que
                    // vao nascem no envio.
                    if (! $row->exists && ! $exclude) {
                        continue;
                    }

                    $row->fill(['excluded' => $exclude])->save();
                }
            }

            return $listing;
        });
    }

    /**
     * Guarda o video do produto ca, para ir no envio seguinte. Se ja havia
     * um na Etsy, esse sai de la.
     */
    public function storeVideo(Product $product, UploadedFile $video): EtsyListing
    {
        $listing = EtsyListing::query()->firstOrNew(['product_id' => $product->getKey()]);

        $this->forgetRemoteVideo($listing);

        // O ficheiro vai para o disco antes de qualquer escrita na base de
        // dados, como as fotografias (ImageService::put).
        $path = (string) $video->store('etsy-videos', 'public');
        $previous = $listing->video_path;

        $listing->fill(['video_path' => $path, 'etsy_video_id' => null])->save();

        if ($previous !== null) {
            Storage::disk('public')->delete($previous);
        }

        return $listing;
    }

    public function removeVideo(Product $product): void
    {
        $listing = EtsyListing::query()->where('product_id', $product->getKey())->first();

        if ($listing === null) {
            return;
        }

        $this->forgetRemoteVideo($listing);

        $path = $listing->video_path;
        $listing->update(['video_path' => null, 'etsy_video_id' => null]);

        if ($path !== null) {
            Storage::disk('public')->delete($path);
        }
    }

    // ── Enviar ─────────────────────────────────────────────────────────────

    public function payload(Product $product): EtsyListingPayload
    {
        return $this->builder->build($product);
    }

    /**
     * Cria ou atualiza o anuncio do produto na Etsy.
     *
     * A ordem importa. Primeiro o anuncio: se for novo, cria-se o rascunho
     * e o numero dele grava-se IMEDIATAMENTE — se o passo seguinte falhar, a
     * proxima tentativa tem de saber que o rascunho ja la esta, senao criava
     * outro igual. Depois o inventario e os atributos. As fotografias e o
     * video vao para a fila, uma de cada vez: sao lentas, e uma que falhe
     * nao pode deitar abaixo o resto.
     */
    public function push(Product $product): EtsyListing
    {
        $shopId = $this->connection()->shop_id;
        $payload = $this->builder->build($product);

        if (! $payload->isReady()) {
            throw new EtsyException('Ainda não dá para enviar este anúncio. '.implode(' ', $payload->blockers));
        }

        $listing = EtsyListing::query()->firstOrNew(['product_id' => $product->getKey()]);

        try {
            $this->pushListing($shopId, $listing, $payload);
            $this->pushInventory($listing, $payload);
            $this->pushProperties($shopId, $listing, $payload);
        } catch (EtsyException $exception) {
            if ($listing->exists) {
                $listing->update(['last_error' => Str::limit($exception->getMessage(), 500, '')]);
            }

            throw $exception;
        }

        $listing->update([
            'last_pushed_at' => now(),
            'last_push_hash' => $payload->hash(),
            'last_error' => null,
            'property_map' => $payload->propertyMap === [] ? $listing->property_map : $payload->propertyMap,
        ]);

        $this->pushImages($shopId, $listing, $payload);

        if ($listing->video_path !== null && $listing->etsy_video_id === null) {
            UploadEtsyListingVideo::dispatch($listing->id);
        }

        return $listing->refresh();
    }

    /**
     * O que ainda esta a caminho depois de um envio. Com a fila a serio, o
     * push() devolve antes de as fotografias e o video terem subido — quem
     * responde ao dono tem de o dizer, em vez de um "esta tudo la".
     *
     * @return array{images: int, video: bool}
     */
    public function pendingUploads(EtsyListing $listing): array
    {
        return [
            'images' => $listing->images()
                ->whereNotNull('product_image_id')
                ->where('excluded', false)
                ->whereNull('etsy_image_id')
                ->count(),
            'video' => $listing->video_path !== null && $listing->etsy_video_id === null,
        ];
    }

    /**
     * O que mudaria na Etsy se o produto fosse enviado agora: o que la esta
     * contra o que iria. Faz uma leitura a Etsy.
     *
     * @return array<int, array{field: string, on_etsy: mixed, would_send: mixed}>
     */
    public function differences(Product $product, EtsyListingPayload $payload): array
    {
        $listing = EtsyListing::query()->where('product_id', $product->getKey())->first();

        if ($listing === null || $listing->etsy_listing_id === null) {
            return [];
        }

        $remote = $this->api->listing($listing->etsy_listing_id, self::DETAIL_INCLUDES);
        $differences = [];

        $note = function (string $field, mixed $onEtsy, mixed $wouldSend) use (&$differences): void {
            if ($onEtsy != $wouldSend) {
                $differences[] = ['field' => $field, 'on_etsy' => $onEtsy, 'would_send' => $wouldSend];
            }
        };

        foreach (['title', 'description', 'taxonomy_id', 'shipping_profile_id', 'shop_section_id', 'who_made', 'when_made'] as $field) {
            $note($field, $this->blankToNull($remote[$field] ?? null), $this->blankToNull($payload->listing[$field] ?? null));
        }

        $sorted = function (mixed $values): array {
            $list = array_map(fn (mixed $value): string => Str::lower((string) $value), is_array($values) ? $values : []);
            sort($list);

            return $list;
        };

        if ($sorted($remote['tags'] ?? null) !== $sorted($payload->listing['tags'] ?? null)) {
            $note('tags', array_values((array) ($remote['tags'] ?? [])), $payload->listing['tags'] ?? []);
        }

        [$there] = $this->remoteSkus($remote);

        foreach ($payload->variants as $variant) {
            $remoteProduct = $there[Str::lower($variant['sku'])] ?? null;

            if ($remoteProduct === null) {
                $note("variação {$variant['sku']}", null, 'nova');

                continue;
            }

            $offering = EtsyPresenter::rows($remoteProduct['offerings'] ?? null)[0] ?? [];
            $remoteCents = EtsyPresenter::cents($offering['price'] ?? null);

            $note("preço {$variant['sku']}", $remoteCents === null ? null : $this->euros($remoteCents), $this->euros($variant['price_cents']));
            $note("quantidade {$variant['sku']}", $offering['quantity'] ?? null, $variant['quantity']);
        }

        $here = array_map(fn (array $variant): string => Str::lower($variant['sku']), $payload->variants);

        foreach (array_keys($there) as $sku) {
            if (! in_array($sku, $here, true)) {
                $note("variação {$sku}", 'existe', 'sai do anúncio');
            }
        }

        $note('fotografias', count(EtsyPresenter::rows($remote['images'] ?? null)), count($payload->images) ?: count(EtsyPresenter::rows($remote['images'] ?? null)));

        return $differences;
    }

    // ── Por dentro: enviar ─────────────────────────────────────────────────

    private function pushListing(int $shopId, EtsyListing $listing, EtsyListingPayload $payload): void
    {
        $fields = $payload->listing;

        if ($listing->etsy_listing_id !== null) {
            // O perfil de processamento nao e um campo do anuncio na edicao:
            // vai em cada oferta do inventario.
            unset($fields['readiness_state_id']);

            $this->api->updateListing($shopId, $listing->etsy_listing_id, $fields);

            return;
        }

        // Para criar, a Etsy quer um total e um preco; o inventario, a
        // seguir, e que diz quanto e a quanto de cada variacao.
        $created = $this->api->createDraftListing($shopId, [
            ...$fields,
            'quantity' => max(1, $payload->totalQuantity()),
            'price' => number_format($payload->lowestPriceCents() / 100, 2, '.', ''),
        ]);

        $etsyListingId = $this->id($created['listing_id'] ?? null);

        if ($etsyListingId === null) {
            throw new EtsyException('A Etsy não devolveu o número do anúncio que criou. Confirma na loja se o rascunho lá está antes de voltar a enviar.');
        }

        // JA, e nao no fim: ver o docblock do push().
        $listing->fill([
            'etsy_listing_id' => $etsyListingId,
            'state' => $this->string($created['state'] ?? null, 20) ?? 'draft',
            'url' => $this->string($created['url'] ?? null, 500),
        ])->save();
    }

    /**
     * O inventario substitui o que la esta. A resposta traz os ids que a
     * Etsy deu a cada variacao, e esses guardam-se: sao eles que identificam
     * a linha de uma encomenda quando a referencia faltar.
     */
    private function pushInventory(EtsyListing $listing, EtsyListingPayload $payload): void
    {
        $response = $this->api->updateListingInventory((int) $listing->etsy_listing_id, $payload->inventory, $payload->inventoryQuery);

        [$there] = $this->remoteSkus(['inventory' => $response]);

        foreach ($payload->variants as $variant) {
            $remoteProduct = $there[Str::lower($variant['sku'])] ?? null;
            $offering = EtsyPresenter::rows($remoteProduct['offerings'] ?? null)[0] ?? [];

            EtsyVariant::query()->updateOrCreate(['variant_id' => $variant['variant_id']], [
                'listing_id' => $listing->id,
                'etsy_product_id' => $this->id($remoteProduct['product_id'] ?? null),
                'etsy_offering_id' => $this->id($offering['offering_id'] ?? null),
                'last_quantity_pushed' => $variant['enabled'] ? $variant['quantity'] : 0,
            ]);
        }
    }

    private function pushProperties(int $shopId, EtsyListing $listing, EtsyListingPayload $payload): void
    {
        foreach ($payload->properties as $property) {
            $this->api->updateListingProperty($shopId, (int) $listing->etsy_listing_id, $property['property_id'], [
                'value_ids' => $property['value_ids'],
                'values' => $property['values'],
                'scale_id' => $property['scale_id'],
            ]);
        }
    }

    /**
     * Poe as fotografias do anuncio iguais as do produto.
     *
     * O que saiu da galeria (ou foi marcado para nao ir) sai de la ja. O que
     * falta carregar vai para a fila, uma fotografia por tarefa. As que ja
     * estavam no anuncio quando foi ligado, postas a mao, so saem DEPOIS de
     * as novas terem subido: o anuncio nunca fica sem fotografia nenhuma.
     */
    private function pushImages(int $shopId, EtsyListing $listing, EtsyListingPayload $payload): void
    {
        $rows = $listing->images()->get();
        $wanted = array_column($payload->images, 'product_image_id');

        foreach ($rows as $row) {
            $adopted = $row->product_image_id === null && $row->source_path === null;

            if ($adopted || in_array($row->product_image_id, $wanted, true)) {
                continue;
            }

            if ($row->etsy_image_id !== null) {
                $this->deleteRemoteImage($shopId, $listing, $row->etsy_image_id);
            }

            // A excluida fica, para continuar a dizer "esta nao vai"; a que
            // foi apagada da galeria ja nao tem o que dizer.
            if ($row->excluded && $row->product_image_id !== null) {
                $row->update(['etsy_image_id' => null, 'rank' => null]);
            } else {
                $row->delete();
            }
        }

        foreach ($payload->images as $image) {
            $row = $rows->firstWhere('product_image_id', $image['product_image_id'])
                ?? new EtsyListingImage(['listing_id' => $listing->id, 'product_image_id' => $image['product_image_id']]);

            $row->fill(['rank' => $image['rank'], 'source_path' => $image['path'], 'excluded' => false])->save();

            if ($row->etsy_image_id === null) {
                UploadEtsyListingImage::dispatch($row->id);
            }
        }

        $hasAdopted = $rows->contains(fn (EtsyListingImage $row): bool => $row->product_image_id === null && $row->source_path === null);

        if ($hasAdopted && $payload->images !== []) {
            PruneEtsyListingImages::dispatch($listing->id);
        }
    }

    /**
     * Carrega uma fotografia para o anuncio. Corre na fila, uma por tarefa
     * (UploadEtsyListingImage).
     *
     * Nunca lanca: uma fotografia recusada fica dita no anuncio e volta a
     * tentar-se no envio seguinte, sem deitar abaixo as outras.
     */
    public function uploadImage(EtsyListingImage $row): void
    {
        $listing = $row->listing;

        if ($listing === null || $listing->etsy_listing_id === null
            || $row->etsy_image_id !== null || $row->excluded || $row->source_path === null) {
            return;
        }

        $this->quietly($listing, "Fotografia {$row->rank}", function () use ($row, $listing): void {
            $contents = Storage::disk('public')->get((string) $row->source_path);

            if ($contents === null) {
                throw new EtsyException("o ficheiro {$row->source_path} já não está no disco.");
            }

            $uploaded = $this->api->uploadListingImage(
                $this->connection()->shop_id,
                (int) $listing->etsy_listing_id,
                $contents,
                basename((string) $row->source_path),
                array_filter([
                    'rank' => $row->rank,
                    'alt_text' => Str::limit((string) $row->productImage?->alt, 500, ''),
                ], fn (mixed $value): bool => $value !== null && $value !== ''),
            );

            $imageId = $this->id($uploaded['listing_image_id'] ?? null);

            if ($imageId === null) {
                throw new EtsyException('a Etsy não devolveu o número da imagem. Confirma no anúncio se ela lá está.');
            }

            $row->update(['etsy_image_id' => $imageId]);
        });
    }

    /**
     * Carrega o video do anuncio, se ainda la nao estiver. Corre na fila
     * (UploadEtsyListingVideo) e, como as fotografias, nunca lanca.
     */
    public function uploadVideo(EtsyListing $listing): void
    {
        if ($listing->video_path === null || $listing->etsy_video_id !== null || $listing->etsy_listing_id === null) {
            return;
        }

        $this->quietly($listing, 'Vídeo', function () use ($listing): void {
            $contents = Storage::disk('public')->get((string) $listing->video_path);

            if ($contents === null) {
                throw new EtsyException("o ficheiro {$listing->video_path} já não está no disco.");
            }

            $uploaded = $this->api->uploadListingVideo(
                $this->connection()->shop_id,
                (int) $listing->etsy_listing_id,
                $contents,
                basename((string) $listing->video_path),
            );

            $videoId = $this->id($uploaded['video_id'] ?? null);

            if ($videoId === null) {
                throw new EtsyException('a Etsy não devolveu o número do vídeo. Confirma no anúncio se ele lá está.');
            }

            $listing->update(['etsy_video_id' => $videoId]);
        });
    }

    /**
     * Tira do anuncio as imagens que ja la estavam quando foi ligado. So
     * depois de TODAS as do produto terem subido: se uma falhou, as antigas
     * ficam ate ao envio seguinte.
     */
    public function pruneAdoptedImages(EtsyListing $listing): void
    {
        $rows = $listing->images()->get();
        $ours = $rows->filter(fn (EtsyListingImage $row): bool => $row->product_image_id !== null && ! $row->excluded);

        if ($ours->isEmpty() || $ours->contains(fn (EtsyListingImage $row): bool => $row->etsy_image_id === null)) {
            return;
        }

        $this->quietly($listing, 'Fotografias antigas', function () use ($rows, $listing): void {
            $shopId = $this->connection()->shop_id;

            foreach ($rows as $row) {
                if ($row->product_image_id !== null || $row->source_path !== null) {
                    continue;
                }

                if ($row->etsy_image_id !== null) {
                    $this->deleteRemoteImage($shopId, $listing, $row->etsy_image_id);
                }

                $row->delete();
            }
        });
    }

    /**
     * Faz um trabalho da fila sem deixar a excecao subir: o que correu mal
     * fica escrito no anuncio, onde o dono o ve.
     */
    private function quietly(EtsyListing $listing, string $what, Closure $work): void
    {
        try {
            $work();
        } catch (EtsyException $exception) {
            $listing->update(['last_error' => Str::limit("{$what}: {$exception->getMessage()}", 500, '')]);
        }
    }

    /**
     * Apagar uma imagem que ja la nao esta nao e erro nenhum.
     */
    private function deleteRemoteImage(int $shopId, EtsyListing $listing, int $etsyImageId): void
    {
        if ($listing->etsy_listing_id === null) {
            return;
        }

        try {
            $this->api->deleteListingImage($shopId, $listing->etsy_listing_id, $etsyImageId);
        } catch (EtsyApiException $exception) {
            if ($exception->status !== 404) {
                throw $exception;
            }
        }
    }

    private function forgetRemoteVideo(EtsyListing $listing): void
    {
        if ($listing->etsy_video_id === null || $listing->etsy_listing_id === null) {
            return;
        }

        try {
            $this->api->deleteListingVideo($this->connection()->shop_id, $listing->etsy_listing_id, $listing->etsy_video_id);
        } catch (EtsyApiException $exception) {
            if ($exception->status !== 404) {
                throw $exception;
            }
        }
    }

    // ── Por dentro: ligar ──────────────────────────────────────────────────

    private function connection(): EtsyConnection
    {
        return $this->tokens->connection() ?? throw EtsyException::notConnected();
    }

    /**
     * Cada variante do produto fica com o "product" e a "offering" que a
     * Etsy deu a mesma referencia. Sem olhar a maiusculas: la o SKU fica como
     * foi escrito a mao. O preco proprio e o "ligada/desligada" de uma
     * variante ja conhecida nao mexem.
     *
     * @param  array<array-key, mixed>  $remote
     */
    private function adoptVariants(EtsyListing $listing, Product $product, array $remote): void
    {
        [$bySku] = $this->remoteSkus($remote);

        foreach ($product->variants()->get(['id', 'sku']) as $variant) {
            $remoteProduct = $bySku[Str::lower($variant->sku)] ?? null;

            if ($remoteProduct === null) {
                continue;
            }

            $offering = EtsyPresenter::rows($remoteProduct['offerings'] ?? null)[0] ?? [];

            EtsyVariant::query()->updateOrCreate(['variant_id' => $variant->id], [
                'listing_id' => $listing->id,
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
     * As variacoes de um anuncio da Etsy pela referencia (em minusculas).
     *
     * Na Etsy e comum a referencia nao variar com a variacao — varias
     * variacoes com o mesmo SKU. Essas nao servem para casar com uma
     * variante nossa: escolher uma ao acaso punha o stock no sitio errado.
     * Vem separadas, em `ambiguous`.
     *
     * @param  array<array-key, mixed>  $remote
     * @return array{0: array<string, array<array-key, mixed>>, 1: array<string, true>}
     */
    private function remoteSkus(array $remote): array
    {
        $inventory = $remote['inventory'] ?? null;
        $unique = [];
        $ambiguous = [];

        foreach (EtsyPresenter::rows(is_array($inventory) ? ($inventory['products'] ?? null) : null) as $remoteProduct) {
            if (($remoteProduct['is_deleted'] ?? false) || ! is_string($remoteProduct['sku'] ?? null) || trim($remoteProduct['sku']) === '') {
                continue;
            }

            $key = Str::lower(trim($remoteProduct['sku']));

            if (isset($unique[$key]) || isset($ambiguous[$key])) {
                unset($unique[$key]);
                $ambiguous[$key] = true;

                continue;
            }

            $unique[$key] = $remoteProduct;
        }

        return [$unique, $ambiguous];
    }

    private function euros(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '');
    }

    private function id(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function string(mixed $value, int $max): ?string
    {
        return is_string($value) && $value !== '' ? Str::limit($value, $max, '') : null;
    }

    private function blankToNull(mixed $value): mixed
    {
        return $value === '' || $value === [] ? null : $value;
    }

    /**
     * @return array<int, string>|null
     */
    private function strings(mixed $values): ?array
    {
        return is_array($values) ? array_values(array_filter($values, 'is_string')) : null;
    }
}
