<?php

namespace App\Http\Controllers\Admin;

use App\Etsy\EtsyException;
use App\Etsy\EtsyInsights;
use App\Etsy\EtsyListingPayload;
use App\Etsy\EtsyListingSync;
use App\Etsy\EtsyPricePolicy;
use App\Etsy\EtsyShipping;
use App\Etsy\EtsyShop;
use App\Etsy\EtsyTokens;
use App\Http\Controllers\Admin\Concerns\AttemptsEtsy;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdminActionRequest;
use App\Http\Requests\Etsy\SaveEtsyListingRequest;
use App\Http\Requests\Etsy\UploadEtsyVideoRequest;
use App\Models\EtsyListing;
use App\Models\EtsyListingImage;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Variant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * O anuncio de um produto na Etsy: o que la iria, o que o impede, e os
 * botoes para guardar o conteudo proprio e para o enviar.
 *
 * Guardar e enviar sao dois passos de proposito. Guardar fica ca; so o
 * "Enviar para a Etsy" escreve la — e antes disso o ecra ja mostrou o que
 * vai e o que falta.
 */
class EtsyProductController extends Controller
{
    use AttemptsEtsy;

    public function __construct(
        private EtsyTokens $tokens,
        private EtsyListingSync $sync,
        private EtsyPricePolicy $policy,
        private EtsyShipping $shipping,
        private EtsyShop $shop,
        private EtsyInsights $insights,
    ) {}

    public function show(Request $request, Product $product): Response
    {
        $payload = $this->sync->payload($product);
        $listing = $product->etsyListing;

        $connected = $this->tokens->isConnected();
        $error = null;
        $options = ['shippingProfiles' => [], 'processingProfiles' => [], 'returnPolicies' => [], 'sections' => []];
        $differences = null;

        // A pagina abre sempre: sem loja ligada ou com a Etsy em baixo ficam
        // as listas vazias e a razao a vista.
        if ($connected) {
            try {
                $options = $this->options();

                // A comparacao com o que la esta custa uma leitura a Etsy:
                // so quando e pedida.
                if ($request->boolean('comparar')) {
                    $differences = $this->sync->differences($product, $payload);
                }
            } catch (EtsyException $exception) {
                $error = $exception->getMessage();
            }
        }

        return Inertia::render('admin/produtos/etsy', [
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'status' => $product->status,
                'fulfillmentMode' => $product->fulfillment_mode,
            ],
            'connected' => $connected,
            'error' => $error,
            'listing' => [
                'onEtsy' => $listing?->isOnEtsy() ?? false,
                'etsyListingId' => $listing?->etsy_listing_id,
                'state' => $listing?->state,
                'url' => $listing?->url,
                'lastPushedAt' => $listing?->last_pushed_at?->toIso8601String(),
                'lastError' => $listing?->last_error,
                // Mudou alguma coisa desde o ultimo envio?
                'pending' => $listing?->last_push_hash !== $payload->hash(),
                'videoUrl' => $listing?->video_path === null ? null : Storage::disk('public')->url($listing->video_path),
                'videoOnEtsy' => $listing?->etsy_video_id !== null,
            ],
            'own' => $this->content($listing),
            'effective' => [
                'title' => $payload->listing['title'] ?? '',
                'description' => $payload->listing['description'] ?? '',
                'tags' => $payload->listing['tags'] ?? [],
                'materials' => $payload->listing['materials'] ?? [],
                'taxonomyId' => $payload->listing['taxonomy_id'] ?? null,
                'whoMade' => $payload->listing['who_made'] ?? null,
                'whenMade' => $payload->listing['when_made'] ?? null,
                'shippingProfileId' => $payload->listing['shipping_profile_id'] ?? null,
                'returnPolicyId' => $payload->listing['return_policy_id'] ?? null,
                'readinessStateId' => $payload->listing['readiness_state_id'] ?? null,
                'shopSectionId' => $payload->listing['shop_section_id'] ?? null,
            ],
            'variants' => $this->variants($product, $payload),
            'images' => $this->images($product, $listing),
            'blockers' => $payload->blockers,
            'warnings' => $payload->warnings,
            'differences' => $differences === null ? null : array_map(fn (array $difference): array => [
                'field' => $difference['field'],
                'onEtsy' => $this->readable($difference['on_etsy']),
                'wouldSend' => $this->readable($difference['would_send']),
            ], $differences),
            'options' => [
                ...$options,
                'whoMade' => (array) config('etsy.listing.who_made'),
                'whenMade' => (array) config('etsy.listing.when_made'),
            ],
            'limits' => [
                'titleMax' => (int) config('etsy.listing.title_max'),
                'tagsMax' => (int) config('etsy.listing.tags_max'),
                'tagLengthMax' => (int) config('etsy.listing.tag_length_max'),
            ],
            // A procura de categorias so corre quando o ecra a pede (recarga
            // parcial com ?categoria=...): a arvore sao milhares de nos.
            'taxonomyResults' => Inertia::optional(fn (): array => $this->searchTaxonomy((string) $request->query('categoria', ''))),
        ]);
    }

    public function save(SaveEtsyListingRequest $request, Product $product): RedirectResponse
    {
        $this->sync->saveContent($product, $request->validated());

        $this->toast('Guardado. Ainda nada foi para a Etsy: isso é o "Enviar para a Etsy".');

        return back();
    }

    public function push(AdminActionRequest $request, Product $product): RedirectResponse
    {
        try {
            $wasThere = EtsyListing::query()->where('product_id', $product->id)->value('etsy_listing_id') !== null;
            $listing = $this->sync->push($product);

            // O anuncio foi, mas uma fotografia ou o video podem ter ficado
            // pelo caminho: diz-se, em vez de um "tudo certo" que nao e.
            if ($listing->last_error !== null) {
                $this->toast("O anúncio foi para a Etsy, mas nem tudo seguiu. {$listing->last_error}", 'error');
            } else {
                $pending = $this->sync->pendingUploads($listing);

                $this->toast(($wasThere
                    ? 'Anúncio atualizado na Etsy.'
                    : "Rascunho criado na Etsy (anúncio {$listing->etsy_listing_id}). Ainda não está à venda.")
                    .($pending['images'] > 0 || $pending['video'] ? ' As fotografias e o vídeo ainda estão a subir.' : ''));
            }
        } catch (EtsyException $exception) {
            $this->toast($exception->getMessage(), 'error');
        }

        return back();
    }

    public function uploadVideo(UploadEtsyVideoRequest $request, Product $product): RedirectResponse
    {
        /** @var UploadedFile $video */
        $video = $request->file('video');

        return $this->attempt(function () use ($product, $video): null {
            $this->sync->storeVideo($product, $video);

            return null;
        }, 'Vídeo guardado. Segue para a Etsy no próximo envio.');
    }

    public function removeVideo(AdminActionRequest $request, Product $product): RedirectResponse
    {
        return $this->attempt(function () use ($product): null {
            $this->sync->removeVideo($product);

            return null;
        }, 'Vídeo retirado.');
    }

    /**
     * O que o dono escreveu de proprio para a Etsy. Vazio = vale o do produto.
     *
     * @return array<string, mixed>
     */
    private function content(?EtsyListing $listing): array
    {
        return [
            'title' => $listing->title ?? '',
            'description' => $listing->description ?? '',
            'tags' => $listing->tags ?? [],
            'materials' => $listing->materials ?? [],
            'taxonomyId' => $listing?->taxonomy_id,
            'whoMade' => $listing?->who_made,
            'whenMade' => $listing?->when_made,
            'shippingProfileId' => $listing?->shipping_profile_id,
            'returnPolicyId' => $listing?->return_policy_id,
            'readinessStateId' => $listing?->readiness_state_id,
            'shopSectionId' => $listing?->shop_section_id,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function variants(Product $product, EtsyListingPayload $payload): array
    {
        $variants = $product->variants()->with('etsyVariant')->get()->keyBy('id');

        return array_values(array_filter(array_map(function (array $row) use ($variants): ?array {
            /** @var Variant|null $variant */
            $variant = $variants->get($row['variant_id']);

            if ($variant === null) {
                return null;
            }

            return [
                'id' => $variant->id,
                'sku' => $row['sku'],
                'label' => $row['label'],
                'sitePriceCents' => $variant->price_cents,
                'etsyPriceCents' => $variant->etsyVariant?->etsy_price_cents,
                'effectivePriceCents' => $row['price_cents'],
                'priceSource' => $this->policy->source($variant),
                'quantity' => $row['quantity'],
                'enabled' => $row['enabled'],
            ];
        }, $payload->variants)));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function images(Product $product, ?EtsyListing $listing): array
    {
        $rows = ($listing->images ?? collect())->keyBy('product_image_id');

        return $product->images
            ->map(function (ProductImage $image) use ($rows): array {
                /** @var EtsyListingImage|null $row */
                $row = $rows->get($image->id);

                return [
                    'id' => $image->id,
                    'url' => $image->url,
                    'alt' => $image->alt,
                    'excluded' => $row->excluded ?? false,
                    'onEtsy' => $row?->etsy_image_id !== null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * As listas de onde o anuncio escolhe: perfis de envio e de
     * processamento, politicas de devolucao e seccoes da loja.
     *
     * @return array{shippingProfiles: array<int, array<string, mixed>>, processingProfiles: array<int, array<string, mixed>>, returnPolicies: array<int, array<string, mixed>>, sections: array<int, array<string, mixed>>}
     */
    private function options(): array
    {
        return [
            'shippingProfiles' => array_map(fn (array $profile): array => [
                'id' => $profile['shipping_profile_id'] ?? null,
                'title' => (string) ($profile['title'] ?? ''),
            ], $this->shipping->profiles()),
            'processingProfiles' => array_map(fn (array $profile): array => [
                'id' => $profile['readiness_state_id'] ?? null,
                'state' => $profile['readiness_state'] ?? null,
                'min' => $profile['min_processing_time'] ?? null,
                'max' => $profile['max_processing_time'] ?? null,
                'unit' => $profile['processing_time_unit'] ?? null,
            ], $this->shipping->processingProfiles()),
            'returnPolicies' => array_map(fn (array $policy): array => [
                'id' => $policy['return_policy_id'] ?? null,
                'acceptsReturns' => (bool) ($policy['accepts_returns'] ?? false),
                'acceptsExchanges' => (bool) ($policy['accepts_exchanges'] ?? false),
                'deadline' => $policy['return_deadline'] ?? null,
            ], $this->shop->returnPolicies()),
            'sections' => array_map(fn (array $section): array => [
                'id' => $section['shop_section_id'],
                'title' => $section['title'],
            ], $this->shop->sections()),
        ];
    }

    /**
     * @return array<int, array{id: int, name: string, path: string, level: int, is_leaf: bool}>
     */
    private function searchTaxonomy(string $query): array
    {
        try {
            return $this->insights->searchTaxonomy($query, 15);
        } catch (EtsyException) {
            return [];
        }
    }

    /**
     * Um valor de uma diferenca, pronto a mostrar: as listas juntas, o que
     * nao existe como texto vazio.
     */
    private function readable(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_array($value) => implode(', ', array_map('strval', array_filter($value, 'is_scalar'))),
            is_scalar($value) => (string) $value,
            default => '',
        };
    }
}
