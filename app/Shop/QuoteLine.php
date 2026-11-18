<?php

namespace App\Shop;

use App\Models\Variant;

/**
 * Uma linha do carrinho com o que a base de dados diz hoje. Um `problem`
 * (uma razao do Buyable) deixa a linha visivel mas fora das contas.
 */
final readonly class QuoteLine
{
    /**
     * @param  array<string, string>|null  $personalization
     * @param  array{int, int}|null  $leadDays
     */
    public function __construct(
        public int $itemId,
        public Variant $variant,
        public string $productName,
        public ?string $variantLabel,
        public ?string $sku,
        public int $qty,
        public ?int $maxQty,
        public int $unitCents,
        public int $surchargeCents,
        public int $lineCents,
        public int $vatRate,
        public ?array $personalization,
        public ?string $mode,
        public ?string $imageUrl,
        public ?array $leadDays,
        public ?string $problem,
    ) {}
}
