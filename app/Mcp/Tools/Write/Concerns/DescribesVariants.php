<?php

namespace App\Mcp\Tools\Write\Concerns;

use App\Models\Material;
use App\Models\Variant;
use App\Support\Money;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * O que variant_create e variant_update partilham: o esquema dos campos, a
 * forma "na lingua do formulario" de uma variante gravada, e a variante de
 * ensaio para o PriceGuard calcular o custo com os valores novos.
 */
trait DescribesVariants
{
    /** Campos que as ferramentas aceitam (stock fica de fora: e o stock_adjust). */
    protected const VARIANT_FIELDS = [
        'sku', 'color_id', 'material_id', 'size_label',
        'normal_price', 'sale_price', 'wholesale_price',
        'filament_weight_grams', 'printing_time_minutes', 'printer_profile_id',
        'packaging_cost', 'components_cost', 'active_labor_minutes',
        'low_stock_threshold', 'is_default', 'active',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function variantFieldsSchema(JsonSchema $schema): array
    {
        return [
            'sku' => $schema->string()->max(60)->description('Referência única.'),
            'color_id' => $schema->integer()->description('Cor (ver colors_list).'),
            'material_id' => $schema->integer()->description('Material (ver materials_list). Tem de existir nessa cor.'),
            'size_label' => $schema->string()->max(60)->description('Tamanho, se houver.'),
            'normal_price' => $schema->string()->description('Preço normal em euros, IVA incluído, ex. "24,90".'),
            'sale_price' => $schema->string()->description('Preço promocional, abaixo do normal. "" (texto vazio) tira a promoção.'),
            'wholesale_price' => $schema->string()->description('Preço de revenda, não acima do preço de venda. "" tira-o.'),
            'filament_weight_grams' => $schema->integer()->min(0)->max(99999)->description('Gramas de filamento por peça.'),
            'printing_time_minutes' => $schema->integer()->min(0)->max(59999)->description('Minutos de impressão por peça.'),
            'printer_profile_id' => $schema->integer()->description('Impressora (vazio = a predefinida).'),
            'packaging_cost' => $schema->string()->description('Custo de embalagem em euros.'),
            'components_cost' => $schema->string()->description('Custo de componentes (ímanes, argolas…) em euros.'),
            'active_labor_minutes' => $schema->integer()->min(0)->max(600)->description('Minutos de trabalho manual.'),
            'low_stock_threshold' => $schema->integer()->min(0)->max(9999)->description('Alerta de stock baixo a partir de.'),
            'is_default' => $schema->boolean()->description('Variante mostrada por omissão na loja.'),
            'active' => $schema->boolean()->description('false = escondida da loja.'),
            'confirm' => $schema->boolean()->description('Só depois de o utilizador aprovar um aviso de preço suspeito.'),
        ];
    }

    /**
     * Uma variante gravada, como o formulario do backoffice a le.
     *
     * @return array<string, mixed>
     */
    protected function variantAsForm(Variant $variant): array
    {
        return [
            'sku' => $variant->sku,
            'color_id' => $variant->color_id,
            'material_id' => $variant->material_id,
            'size_label' => $variant->size_label,
            'normal_price' => Money::toDecimal($variant->normalPriceCents()),
            'sale_price' => $this->decimalOrNull($variant->salePriceCents()),
            'wholesale_price' => $this->decimalOrNull($variant->wholesale_price_cents),
            'filament_weight_grams' => $variant->filament_weight_grams,
            'printing_time_minutes' => $variant->printing_time_minutes,
            'printer_profile_id' => $variant->printer_profile_id,
            'packaging_cost' => $this->decimalOrNull($variant->packaging_cost_cents),
            'components_cost' => $this->decimalOrNull($variant->components_cost_cents),
            'active_labor_minutes' => $variant->active_labor_minutes,
            'stock' => $variant->stock,
            'low_stock_threshold' => $variant->low_stock_threshold,
            'is_default' => $variant->is_default,
            'active' => $variant->active,
        ];
    }

    /**
     * Preco efetivo (o que o cliente paga) de um payload ja validado.
     *
     * @param  array<string, mixed>  $data
     */
    protected function effectiveCents(array $data): int
    {
        $sale = $data['sale_price'] ?? null;

        return ($sale === null || $sale === '')
            ? Money::fromDecimal((string) $data['normal_price'])
            : Money::fromDecimal((string) $sale);
    }

    /**
     * Variante nao gravada com os valores de producao novos, so para o
     * PriceGuard calcular o custo com eles.
     *
     * @param  array<string, mixed>  $data
     */
    protected function costProbe(array $data): Variant
    {
        $probe = new Variant([
            'filament_weight_grams' => $data['filament_weight_grams'] ?? null,
            'printing_time_minutes' => $data['printing_time_minutes'] ?? null,
            'printer_profile_id' => $data['printer_profile_id'] ?? null,
            'active_labor_minutes' => $data['active_labor_minutes'] ?? null,
            'packaging_cost_cents' => $this->centsOrNull($data['packaging_cost'] ?? null),
            'components_cost_cents' => $this->centsOrNull($data['components_cost'] ?? null),
        ]);

        $materialId = $data['material_id'] ?? null;
        $probe->setRelation('material', $materialId === null ? null : Material::query()->find((int) $materialId));

        return $probe;
    }

    private function decimalOrNull(?int $cents): ?string
    {
        return $cents === null ? null : Money::toDecimal($cents);
    }

    private function centsOrNull(mixed $value): ?int
    {
        return ($value === null || $value === '') ? null : Money::fromDecimal((string) $value);
    }
}
