<?php

namespace App\Etsy;

use App\Models\EtsyListing;
use App\Models\EtsyListingImage;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Variant;
use Illuminate\Support\Collection;

/**
 * Um produto do 12studio, visto como a Etsy o quer receber.
 *
 * Nao fala com ninguem: le o produto, as predefinicoes e o conteudo Etsy que
 * o produto tiver, e devolve o que seria enviado — mais o que impede o envio
 * (`blockers`) e o que convem saber (`warnings`). Dizer ca o que falta e
 * melhor do que deixar a Etsy dize-lo em ingles, um erro de cada vez.
 *
 * Regra das variacoes: so vira variacao o que realmente varia. Um produto
 * com duas cores e um so material tem UMA variacao (a cor); o material nao e
 * escolha nenhuma e vai para os materiais do anuncio.
 */
class EtsyListingBuilder
{
    /** As dimensoes de uma variante, pela ordem em que viram variacoes. */
    private const DIMENSIONS = ['color', 'material', 'size'];

    public function __construct(
        private EtsySettings $settings,
        private EtsyPricePolicy $policy,
    ) {}

    public function build(Product $product): EtsyListingPayload
    {
        $product->loadMissing(['etsyListing.images', 'tags', 'images']);

        $listing = $product->etsyListing;
        $blockers = [];
        $warnings = [];

        $variants = $product->variants()
            ->where('active', true)
            ->with(['color', 'material', 'etsyVariant'])
            ->orderBy('id')
            ->get()
            ->each(fn (Variant $variant) => $variant->setRelation('product', $product));

        $readinessStateId = $listing->readiness_state_id ?? ($product->fulfillment_mode === 'in_stock'
            ? $this->settings->readyToShipProfileId()
            : $this->settings->madeToOrderProfileId());

        if ($readinessStateId === null) {
            $blockers[] = sprintf(
                'Falta o perfil de processamento para produtos %s (no anúncio ou nas predefinições, em Etsy → Envios).',
                $product->fulfillment_mode === 'in_stock' ? 'em stock' : 'feitos por encomenda',
            );
        }

        [$inventory, $inventoryQuery, $summary, $propertyMap] = $this->inventory(
            $product, $listing, $variants, $readinessStateId, $blockers,
        );

        $listingFields = $this->listingFields($product, $listing, $variants, $readinessStateId, $blockers, $warnings);
        $images = $this->images($product, $listing, $warnings);

        return new EtsyListingPayload(
            listing: $listingFields,
            inventory: $inventory,
            inventoryQuery: $inventoryQuery,
            variants: $summary,
            images: $images,
            properties: $this->properties($listing),
            propertyMap: $propertyMap,
            videoPath: $listing?->video_path,
            blockers: $blockers,
            warnings: $warnings,
        );
    }

    /**
     * O HTML do editor em texto simples: a Etsy nao aceita marcacao na
     * descricao. Os paragrafos ficam separados por uma linha e as listas com
     * uma bolinha, que e o mais perto que o texto simples chega.
     */
    public static function plainText(?string $html): string
    {
        $text = (string) $html;
        $text = (string) preg_replace('/<br\s*\/?>/i', "\n", $text);
        $text = (string) preg_replace('/<li[^>]*>/i', '• ', $text);
        $text = (string) preg_replace('/<\/li>/i', "\n", $text);
        $text = (string) preg_replace('/<\/(p|div|h[1-6]|ul|ol|blockquote)>/i', "\n\n", $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace("/[ \t]+\n/", "\n", $text);
        $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }

    /**
     * @param  Collection<int, Variant>  $variants
     * @param  array<int, string>  $blockers
     * @param  array<int, string>  $warnings
     * @return array<string, mixed>
     */
    private function listingFields(
        Product $product,
        ?EtsyListing $listing,
        Collection $variants,
        ?int $readinessStateId,
        array &$blockers,
        array &$warnings,
    ): array {
        $limits = (array) config('etsy.listing');

        $title = $this->filled($listing?->title) ?? $product->name;

        // Recusa em vez de cortar: um titulo cortado a meio de uma palavra
        // ia para a montra sem ninguem o ter lido.
        if (mb_strlen($title) > (int) $limits['title_max']) {
            $blockers[] = sprintf('O título tem %d caracteres; a Etsy aceita até %d.', mb_strlen($title), $limits['title_max']);
        }

        $description = $this->filled($listing?->description) ?? self::plainText($product->description);

        if ($description === '') {
            $blockers[] = 'Falta a descrição: a Etsy não aceita anúncios sem ela.';
        }

        $taxonomyId = $listing->taxonomy_id ?? $this->settings->defaultTaxonomyId();

        if ($taxonomyId === null) {
            $blockers[] = 'Falta a categoria da Etsy (no anúncio ou nas predefinições, em Etsy → Preços).';
        }

        $shippingProfileId = $listing->shipping_profile_id ?? $this->settings->defaultShippingProfileId();

        if ($shippingProfileId === null) {
            $blockers[] = 'Falta o perfil de envio (no anúncio ou nas predefinições, em Etsy → Envios).';
        }

        $materials = $listing->materials ?? $variants
            ->map(fn (Variant $variant): ?string => $variant->material?->name)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $fields = [
            'title' => $title,
            'description' => $description,
            'who_made' => $listing->who_made ?? $this->settings->whoMade(),
            'when_made' => $listing->when_made ?? $this->settings->whenMade(),
            'taxonomy_id' => $taxonomyId,
            'shipping_profile_id' => $shippingProfileId,
            'return_policy_id' => $listing->return_policy_id ?? $this->settings->defaultReturnPolicyId(),
            'shop_section_id' => $listing->shop_section_id ?? $this->settings->sectionFor($product->category_id),
            'readiness_state_id' => $readinessStateId,
            'tags' => $this->tags($product, $listing, $warnings),
            'materials' => array_slice(array_values($materials), 0, (int) $limits['materials_max']),
            'is_supply' => false,
            'type' => 'physical',
        ];

        return [...$fields, ...$this->measures($variants)];
    }

    /**
     * @param  array<int, string>  $warnings
     * @return array<int, string>
     */
    private function tags(Product $product, ?EtsyListing $listing, array &$warnings): array
    {
        $limits = (array) config('etsy.listing');
        $source = $listing->tags ?? $product->tags->pluck('name')->all();
        $tags = [];

        foreach ($source as $tag) {
            $tag = trim((string) $tag);

            if ($tag === '') {
                continue;
            }

            if (mb_strlen($tag) > (int) $limits['tag_length_max']) {
                $warnings[] = sprintf('A etiqueta "%s" tem mais de %d caracteres e fica de fora.', $tag, $limits['tag_length_max']);

                continue;
            }

            $tags[] = $tag;
        }

        $tags = array_values(array_unique($tags));

        if (count($tags) > (int) $limits['tags_max']) {
            $warnings[] = sprintf('O produto tem %d etiquetas; a Etsy aceita %d. Só vão as primeiras.', count($tags), $limits['tags_max']);
            $tags = array_slice($tags, 0, (int) $limits['tags_max']);
        }

        return $tags;
    }

    /**
     * O peso e as medidas da embalagem, da primeira variante que os tiver.
     * A Etsy quer um so conjunto por anuncio.
     *
     * @param  Collection<int, Variant>  $variants
     * @return array<string, int|string>
     */
    private function measures(Collection $variants): array
    {
        $measures = [];

        $weighed = $variants->first(fn (Variant $variant): bool => ($variant->package_weight_grams ?? $variant->product_weight_grams) !== null);

        if ($weighed !== null) {
            $measures['item_weight'] = (int) ($weighed->package_weight_grams ?? $weighed->product_weight_grams);
            $measures['item_weight_unit'] = 'g';
        }

        $measured = $variants->first(fn (Variant $variant): bool => $variant->length_mm !== null && $variant->width_mm !== null && $variant->height_mm !== null);

        if ($measured !== null) {
            $measures['item_length'] = (int) $measured->length_mm;
            $measures['item_width'] = (int) $measured->width_mm;
            $measures['item_height'] = (int) $measured->height_mm;
            $measures['item_dimensions_unit'] = 'mm';
        }

        return $measures;
    }

    /**
     * @param  Collection<int, Variant>  $variants
     * @param  array<int, string>  $blockers
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<int, array{variant_id: int, sku: string, label: string, price_cents: int, quantity: int, enabled: bool}>, 3: array<string, int>}
     */
    private function inventory(Product $product, ?EtsyListing $listing, Collection $variants, ?int $readinessStateId, array &$blockers): array
    {
        $empty = [
            'products' => [], 'price_on_property' => [], 'quantity_on_property' => [],
            'sku_on_property' => [], 'readiness_state_on_property' => [],
        ];

        if ($variants->isEmpty()) {
            $blockers[] = 'O produto não tem nenhuma variante ativa para anunciar.';

            return [$empty, [], [], []];
        }

        $values = $variants->mapWithKeys(fn (Variant $variant): array => [$variant->id => [
            'color' => $this->valueName($variant->color?->name),
            'material' => $this->valueName($variant->material?->name),
            'size' => $this->valueName($variant->size_label),
        ]])->all();

        // So e variacao o que varia. Com uma variante so, nada varia.
        $varying = array_values(array_filter(
            self::DIMENSIONS,
            fn (string $dimension): bool => count(array_unique(array_filter(array_column($values, $dimension)))) > 1,
        ));

        $propertyMap = $this->propertyMap($listing, $varying, $blockers);
        $labels = (array) config('etsy.variations.labels');
        $seen = [];
        $products = [];
        $summary = [];

        foreach ($variants as $variant) {
            $own = $values[$variant->id];

            foreach ($varying as $dimension) {
                if ($own[$dimension] === null) {
                    $blockers[] = sprintf(
                        'A variante %s não tem %s e as outras têm: na Etsy todas as variações precisam de um valor.',
                        $variant->sku,
                        mb_strtolower((string) ($labels[$dimension] ?? $dimension)),
                    );
                }
            }

            // Duas variantes com os mesmos valores sao a mesma opcao para o
            // comprador: nao teria como escolher entre elas.
            $key = implode('|', array_map(fn (string $dimension): string => mb_strtolower((string) $own[$dimension]), $varying));

            if (isset($seen[$key])) {
                $blockers[] = sprintf(
                    'As variantes %s e %s são iguais para o comprador%s. Muda a cor, o material ou o tamanho de uma, ou esconde-a.',
                    $seen[$key],
                    $variant->sku,
                    $varying === [] ? '' : ' ('.implode(' / ', array_filter(array_map(fn (string $d): ?string => $own[$d], $varying))).')',
                );
            }

            $seen[$key] ??= $variant->sku;

            $priceCents = $this->policy->priceCents($variant);
            $enabled = $variant->etsyVariant->enabled ?? true;
            $quantity = $this->quantity($product, $variant);

            if ($priceCents <= 0) {
                $blockers[] = "A variante {$variant->sku} tem o preço a zero.";
            }

            $products[] = [
                'sku' => $variant->sku,
                'property_values' => array_map(fn (string $dimension): array => [
                    'property_id' => $propertyMap[$dimension] ?? 0,
                    'property_name' => (string) ($labels[$dimension] ?? $dimension),
                    // Propriedades personalizadas nao tem valores com id: vai
                    // o texto.
                    'value_ids' => [],
                    'values' => [(string) $own[$dimension]],
                ], $varying),
                'offerings' => [[
                    'price' => round($priceCents / 100, 2),
                    'quantity' => $quantity,
                    'is_enabled' => $enabled,
                    'readiness_state_id' => $readinessStateId,
                ]],
            ];

            $summary[] = [
                'variant_id' => $variant->id,
                'sku' => $variant->sku,
                'label' => implode(' / ', array_filter(array_map(
                    fn (string $dimension): ?string => $own[$dimension],
                    $varying === [] ? self::DIMENSIONS : $varying,
                ))) ?: $variant->sku,
                'price_cents' => $priceCents,
                'quantity' => $quantity,
                'enabled' => $enabled,
            ];
        }

        if (array_sum(array_map(fn (array $row): int => $row['enabled'] ? $row['quantity'] : 0, $summary)) === 0) {
            $blockers[] = 'Não há stock para anunciar: todas as variantes estão a zero. A Etsy não aceita um anúncio sem nada para vender.';
        }

        $ids = array_map(fn (string $dimension): int => $propertyMap[$dimension] ?? 0, $varying);

        return [
            [
                'products' => $products,
                // Preco, quantidade e referencia dependem de todas as
                // variacoes: cada combinacao e uma variante nossa, com o seu
                // preco e o seu stock.
                'price_on_property' => $ids,
                'quantity_on_property' => $ids,
                'sku_on_property' => $ids,
                // O perfil de processamento e o mesmo para o anuncio inteiro.
                'readiness_state_on_property' => [],
            ],
            count($ids) === 3 ? ['max_variations_supported' => 3] : [],
            $summary,
            $propertyMap,
        ];
    }

    /**
     * Que propriedade da Etsy fica com que dimensao. O que ja foi escolhido
     * num envio anterior nao muda: trocar o numero de uma variacao baralhava
     * o anuncio que la esta.
     *
     * @param  array<int, string>  $varying
     * @param  array<int, string>  $blockers
     * @return array<string, int>
     */
    private function propertyMap(?EtsyListing $listing, array $varying, array &$blockers): array
    {
        /** @var array<int, int> $available */
        $available = array_map('intval', (array) config('etsy.variations.property_ids'));
        $previous = (array) ($listing->property_map ?? []);
        $map = [];

        foreach ($varying as $dimension) {
            if (is_numeric($previous[$dimension] ?? null) && ! in_array((int) $previous[$dimension], $map, true)) {
                $map[$dimension] = (int) $previous[$dimension];
            }
        }

        foreach ($varying as $dimension) {
            if (isset($map[$dimension])) {
                continue;
            }

            $free = array_values(array_diff($available, $map));

            if ($free === []) {
                $blockers[] = 'O produto varia em mais dimensões do que a Etsy aceita num anúncio.';

                continue;
            }

            $map[$dimension] = $free[0];
        }

        return $map;
    }

    /**
     * Quanto se anuncia. O que esta na prateleira e o que la esta, menos o
     * reservado e menos a folga; o que se faz por encomenda nao tem
     * prateleira, e leva o numero das predefinicoes.
     */
    private function quantity(Product $product, Variant $variant): int
    {
        $quantity = $product->fulfillment_mode === 'in_stock'
            ? $variant->stock - $variant->reserved_stock - $this->settings->stockBuffer()
            : $this->settings->mtoQuantity();

        return max(0, min((int) config('etsy.listing.quantity_max', 999), $quantity));
    }

    /**
     * As fotografias da galeria, pela ordem de la, sem as que o dono marcou
     * para nao irem.
     *
     * @param  array<int, string>  $warnings
     * @return array<int, array{product_image_id: int, path: string, alt: string|null, rank: int}>
     */
    private function images(Product $product, ?EtsyListing $listing, array &$warnings): array
    {
        $rows = $listing->images ?? collect();
        $excluded = $rows->filter(fn (EtsyListingImage $row): bool => $row->excluded)->pluck('product_image_id')->filter()->all();

        $wanted = $product->images
            ->reject(fn (ProductImage $image): bool => in_array($image->id, $excluded, true))
            ->values();

        $max = (int) config('etsy.listing.images_max', 20);

        if ($wanted->count() > $max) {
            $warnings[] = sprintf('O produto tem %d fotografias; a Etsy aceita %d. Só vão as primeiras.', $wanted->count(), $max);
            $wanted = $wanted->take($max)->values();
        }

        if ($wanted->isEmpty()) {
            $warnings[] = 'O produto não tem fotografias para enviar: a Etsy não publica um anúncio sem pelo menos uma.';
        }

        // As que ja estavam no anuncio quando foi ligado, postas a mao.
        $adopted = $rows->filter(fn (EtsyListingImage $row): bool => $row->product_image_id === null && $row->source_path === null)->count();

        if ($adopted > 0 && $wanted->isNotEmpty()) {
            $warnings[] = sprintf(
                'As %d fotografia(s) que já estão no anúncio da Etsy vão ser substituídas pelas %d do produto.',
                $adopted,
                $wanted->count(),
            );
        }

        return $wanted->map(fn (ProductImage $image, int $index): array => [
            'product_image_id' => $image->id,
            'path' => $image->path,
            'alt' => $image->alt,
            'rank' => $index + 1,
        ])->all();
    }

    /**
     * @return array<int, array{property_id: int, value_ids: array<int, int>, values: array<int, string>, scale_id: int|null}>
     */
    private function properties(?EtsyListing $listing): array
    {
        $properties = [];

        foreach ((array) ($listing->properties ?? []) as $property) {
            if (! is_array($property) || ! is_numeric($property['property_id'] ?? null)) {
                continue;
            }

            $properties[] = [
                'property_id' => (int) $property['property_id'],
                'value_ids' => array_values(array_map('intval', array_filter((array) ($property['value_ids'] ?? []), 'is_numeric'))),
                'values' => array_values(array_map('strval', array_filter((array) ($property['values'] ?? []), 'is_scalar'))),
                'scale_id' => is_numeric($property['scale_id'] ?? null) ? (int) $property['scale_id'] : null,
            ];
        }

        return $properties;
    }

    /**
     * O nome de um valor de variacao, como a Etsy o aceita: sem parenteses
     * (recusa-os) e sem espacos a mais.
     */
    private function valueName(?string $name): ?string
    {
        $clean = trim((string) preg_replace('/\s+/', ' ', str_replace(['(', ')'], '', (string) $name)));

        return $clean === '' ? null : $clean;
    }

    private function filled(?string $value): ?string
    {
        return $value !== null && trim($value) !== '' ? $value : null;
    }
}
