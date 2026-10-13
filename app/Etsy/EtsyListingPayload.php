<?php

namespace App\Etsy;

/**
 * O que seria enviado para a Etsy por um produto, e o que o impede.
 *
 * So dados: quem o construiu (EtsyListingBuilder) nao falou com ninguem, e
 * quem o envia (EtsyListingSync) nao decide nada.
 */
final readonly class EtsyListingPayload
{
    /**
     * @param  array<string, mixed>  $listing  Os campos do anuncio (criar e editar).
     * @param  array<string, mixed>  $inventory  O corpo do updateListingInventory.
     * @param  array<string, mixed>  $inventoryQuery  `max_variations_supported`, quando sao tres.
     * @param  array<int, array{variant_id: int, sku: string, label: string, price_cents: int, quantity: int, enabled: bool}>  $variants
     * @param  array<int, array{product_image_id: int, path: string, alt: string|null, rank: int}>  $images
     * @param  array<int, array{property_id: int, value_ids: array<int, int>, values: array<int, string>, scale_id: int|null}>  $properties
     * @param  array<string, int>  $propertyMap  Dimensao (color, material, size) => id da propriedade na Etsy.
     * @param  array<int, string>  $blockers  O que impede o envio. Vazio = pode ir.
     * @param  array<int, string>  $warnings  O que vai, mas convem saber.
     */
    public function __construct(
        public array $listing,
        public array $inventory,
        public array $inventoryQuery,
        public array $variants,
        public array $images,
        public array $properties,
        public array $propertyMap,
        public ?string $videoPath,
        public array $blockers,
        public array $warnings,
    ) {}

    public function isReady(): bool
    {
        return $this->blockers === [];
    }

    /** O total anunciado, somando as variacoes ligadas. */
    public function totalQuantity(): int
    {
        return array_sum(array_map(
            fn (array $variant): int => $variant['enabled'] ? $variant['quantity'] : 0,
            $this->variants,
        ));
    }

    /** O preco mais baixo entre as variacoes ligadas, em centimos. */
    public function lowestPriceCents(): int
    {
        $prices = array_column(array_filter($this->variants, fn (array $variant): bool => $variant['enabled']), 'price_cents');

        return $prices === [] ? 0 : min($prices);
    }

    /**
     * A impressao digital do que seria enviado. Se nao mudou desde o ultimo
     * envio, nao ha nada para mandar — e e assim que a sincronizacao sabe
     * que um preco ou um nome mudaram sem ninguem lhe ter dito.
     */
    public function hash(): string
    {
        return hash('sha256', (string) json_encode([
            $this->listing,
            $this->inventory,
            $this->inventoryQuery,
            array_map(fn (array $image): array => [$image['path'], $image['alt'], $image['rank']], $this->images),
            $this->properties,
            $this->videoPath,
        ]));
    }
}
