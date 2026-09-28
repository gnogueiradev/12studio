<?php

namespace App\Mcp\Tools\Write;

use App\Mcp\McpFormat;
use App\Mcp\Tools\WriteTool;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Services\VariantProductionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('variants_apply_calculated_prices')]
#[Title('Aplicar preços calculados')]
#[Description('Põe nas variantes de um produto o preço normal e de revenda que a calculadora sugere (a mesma do backoffice). Por omissão é só um ENSAIO (dry_run: true): mostra o antes e o depois sem gravar. Para aplicar, mostra o ensaio ao utilizador e, se ele aprovar, repete com "dry_run": false. Uma promoção que continue abaixo do preço novo mantém-se. Variantes sem peso, tempo ou material ficam como estão.')]
#[IsDestructive(false)]
#[IsIdempotent]
class VariantsApplyCalculatedPricesTool extends WriteTool
{
    /** Teto de variantes por chamada. */
    public const MAX_VARIANTS = 50;

    public function __construct(
        private VariantProductionService $production,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'product_id' => $schema->integer()->required()->description('Id do produto.'),
            'variant_ids' => $schema->array()->items($schema->integer())->max(self::MAX_VARIANTS)
                ->description('Só estas variantes do produto. Sem isto, todas (até '.self::MAX_VARIANTS.').'),
            'dry_run' => $schema->boolean()->description('true (por omissão) = só mostra; false = grava.'),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'variant_ids' => ['nullable', 'array', 'max:'.self::MAX_VARIANTS],
            'variant_ids.*' => ['integer'],
            'dry_run' => ['nullable', 'boolean'],
        ]);

        $product = Product::query()->find((int) $data['product_id']);

        if ($product === null) {
            return Response::error('Produto não encontrado.');
        }

        /** @var Collection<int, Variant> $variants */
        $variants = $product->variants()
            ->with('material')
            ->when(isset($data['variant_ids']), fn ($query) => $query->whereIn('id', $data['variant_ids']))
            ->orderBy('id')
            ->get();

        if ($variants->isEmpty()) {
            return Response::error('Nenhuma variante deste produto corresponde ao pedido.');
        }

        if ($variants->count() > self::MAX_VARIANTS) {
            return Response::error('Este produto tem mais de '.self::MAX_VARIANTS.' variantes: indica quais em variant_ids.');
        }

        // Omisso = ensaio. So um false explicito grava.
        $dryRun = ($data['dry_run'] ?? true) !== false;

        $plan = $variants->map(fn (Variant $variant): array => [
            'variant' => $variant,
            'prices' => $this->production->calculatedPricesFor($variant),
        ]);

        $rows = $plan->map(fn (array $row): array => $this->row($row['variant'], $row['prices']))->values()->all();

        if ($dryRun) {
            return Response::structured([
                'dry_run' => true,
                'message' => 'Ensaio: nada foi gravado. Mostra esta tabela ao utilizador; para aplicar, repete com "dry_run": false.',
                'variants' => $rows,
            ]);
        }

        $applied = DB::transaction(fn (): int => $plan
            ->filter(fn (array $row): bool => $row['prices'] !== null)
            ->each(fn (array $row) => $this->production->applyCalculatedPriceTo($row['variant']))
            ->count());

        $this->changes = [
            'product_id' => $product->id,
            'variants' => collect($rows)->filter(fn (array $row): bool => $row['suggested'] !== null)->values()->all(),
        ];

        return Response::structured([
            'dry_run' => false,
            'message' => "Preços aplicados a {$applied} variante(s); ".($variants->count() - $applied).' ficaram como estavam (sem dados para calcular).',
            'variants' => $rows,
        ]);
    }

    /**
     * @param  array{normal_cents: int, sale_cents: int|null, wholesale_cents: int|null}|null  $prices
     * @return array<string, mixed>
     */
    private function row(Variant $variant, ?array $prices): array
    {
        return [
            'variant_id' => $variant->id,
            'sku' => $variant->sku,
            'current' => [
                'normal_price' => McpFormat::money($variant->normalPriceCents()),
                'sale_price' => McpFormat::moneyOrNull($variant->salePriceCents()),
                'wholesale_price' => McpFormat::moneyOrNull($variant->wholesale_price_cents),
            ],
            'suggested' => $prices === null ? null : [
                'normal_price' => McpFormat::money($prices['normal_cents']),
                'sale_price' => McpFormat::moneyOrNull($prices['sale_cents']),
                'wholesale_price' => McpFormat::moneyOrNull($prices['wholesale_cents']),
            ],
            'note' => $prices === null ? 'Sem peso, tempo de impressão ou material: não há cálculo.' : null,
        ];
    }
}
