<?php

namespace App\Mcp\Tools\Etsy;

use App\Etsy\EtsyListingSync;
use App\Etsy\EtsyPricePolicy;
use App\Mcp\McpFormat;
use App\Mcp\Tools\Etsy\Concerns\TalksToEtsy;
use App\Mcp\Tools\StudioTool;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('etsy_listing_preview')]
#[Title('Ver o que iria para a Etsy')]
#[Description('O anúncio que o 12studio enviaria para a Etsy por um produto, sem enviar nada: título, descrição, etiquetas, categoria, perfis, cada variação com preço e quantidade, as fotografias — e o que IMPEDE o envio ("blockers") e o que convém saber ("warnings"). Com "compare": true lê também o anúncio que já está na Etsy e lista o que mudaria ("differences": o que lá está contra o que iria). Usa-o sempre antes de etsy_listing_push, sobretudo no primeiro envio de um anúncio que já existia na Etsy.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld]
class EtsyListingPreviewTool extends StudioTool
{
    use TalksToEtsy;

    public function __construct(
        private EtsyListingSync $sync,
        private EtsyPricePolicy $policy,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'product_id' => $schema->integer()->min(1)->required()->description('Id do produto no 12studio.'),
            'compare' => $schema->boolean()->description('true para comparar com o anúncio que já está na Etsy (faz uma leitura à Etsy).'),
        ];
    }

    protected function etsy(Request $request, User $user): Response|ResponseFactory
    {
        $data = $request->validate(['product_id' => ['required', 'integer', 'min:1']]);
        $product = Product::query()->find((int) $data['product_id']);

        if ($product === null) {
            return Response::error('Produto não encontrado.');
        }

        $payload = $this->sync->payload($product);
        $listing = $product->etsyListing;
        $variants = $product->variants()->with('etsyVariant')->get()->keyBy('id');

        $preview = [
            'product' => ['id' => $product->id, 'name' => $product->name, 'status' => $product->status],
            'on_etsy' => $listing?->isOnEtsy() ?? false,
            'etsy_listing_id' => $listing?->etsy_listing_id,
            'state' => $listing?->state,
            'url' => $listing?->url,
            'last_pushed_at' => $listing?->last_pushed_at?->toIso8601String(),
            'last_error' => $listing?->last_error,
            // O 12studio acompanha este anuncio sozinho? E foi ele que o tirou
            // de venda (sem stock, produto arquivado)?
            'sync_enabled' => $listing->sync_enabled ?? true,
            'auto_deactivated' => $listing?->auto_deactivated_at !== null,
            // Mudou alguma coisa desde o ultimo envio?
            'pending_changes' => $listing?->last_push_hash !== $payload->hash(),
            'ready' => $payload->isReady(),
            'blockers' => $payload->blockers,
            'warnings' => $payload->warnings,
            'listing' => $payload->listing,
            'variants' => array_map(function (array $row) use ($variants): array {
                /** @var Variant|null $variant */
                $variant = $variants->get($row['variant_id']);

                return [
                    'variant_id' => $row['variant_id'],
                    'sku' => $row['sku'],
                    'label' => $row['label'],
                    'price' => McpFormat::money($row['price_cents']),
                    'price_source' => $variant === null ? null : $this->policy->source($variant),
                    'site_price' => McpFormat::moneyOrNull($variant?->price_cents),
                    'quantity' => $row['quantity'],
                    'enabled' => $row['enabled'],
                ];
            }, $payload->variants),
            'images' => array_map(fn (array $image): array => [
                'product_image_id' => $image['product_image_id'],
                'rank' => $image['rank'],
                'alt' => $image['alt'],
            ], $payload->images),
            'has_video' => $payload->videoPath !== null,
            'properties' => $payload->properties,
        ];

        if ($request->boolean('compare')) {
            $preview['differences'] = $this->sync->differences($product, $payload);
            $preview['differences_note'] = match (true) {
                ! $preview['on_etsy'] => 'Este produto ainda não tem anúncio na Etsy: o envio cria um rascunho novo.',
                $preview['differences'] === [] => 'O que iria é igual ao que lá está.',
                default => 'O envio substitui o que está na Etsy pelo que está em "would_send".',
            };
        }

        return Response::structured($preview);
    }
}
