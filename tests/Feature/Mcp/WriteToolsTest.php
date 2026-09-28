<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\StudioServer;
use App\Mcp\Tools\Write\ProductArchiveTool;
use App\Mcp\Tools\Write\ProductCreateTool;
use App\Mcp\Tools\Write\ProductRestoreTool;
use App\Mcp\Tools\Write\ProductUpdateTool;
use App\Mcp\Tools\Write\StockAdjustTool;
use App\Mcp\Tools\Write\VariantArchiveTool;
use App\Mcp\Tools\Write\VariantCreateTool;
use App\Mcp\Tools\Write\VariantsApplyCalculatedPricesTool;
use App\Mcp\Tools\Write\VariantSetDefaultTool;
use App\Mcp\Tools\Write\VariantUpdateTool;
use App\Models\Color;
use App\Models\Material;
use App\Models\McpActivity;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\Testing\PendingTestResponse;
use Laravel\Passport\Passport;
use Tests\TestCase;

class WriteToolsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    /**
     * Admin com um token de leitura e escrita, como uma chave real.
     */
    private function writer(): PendingTestResponse
    {
        Passport::actingAs($this->admin, ['mcp:read', 'mcp:write']);

        return StudioServer::actingAs($this->admin);
    }

    private function reader(): PendingTestResponse
    {
        Passport::actingAs($this->admin, ['mcp:read']);

        return StudioServer::actingAs($this->admin);
    }

    private function variant(array $attributes = []): Variant
    {
        return Variant::factory()->create([
            'price_cents' => 2000,
            'compare_at_cents' => null,
            'wholesale_price_cents' => 1200,
            ...$attributes,
        ]);
    }

    // ── Permissoes ─────────────────────────────────────────────────────────

    public function test_a_read_only_key_cannot_write(): void
    {
        $variant = $this->variant();

        // Nem sequer existe para uma chave de leitura (shouldRegister); a
        // recusa dentro do handle() e a segunda camada.
        $this->reader()->tool(VariantUpdateTool::class, ['variant_id' => $variant->id, 'normal_price' => '21'])
            ->assertNotRegistered();

        $this->assertSame(2000, $variant->refresh()->price_cents);
    }

    public function test_writes_have_their_own_rate_limit(): void
    {
        $variant = $this->variant();

        for ($i = 0; $i < 20; $i++) {
            $this->writer()->tool(StockAdjustTool::class, ['variant_id' => $variant->id, 'delta' => 1, 'note' => 'teste'])->assertOk();
        }

        $this->writer()->tool(StockAdjustTool::class, ['variant_id' => $variant->id, 'delta' => 1, 'note' => 'teste'])
            ->assertHasErrors(['Demasiadas alterações seguidas. Espera um minuto.']);
    }

    // ── Produtos ───────────────────────────────────────────────────────────

    public function test_a_new_product_is_a_draft_by_default_and_gets_the_matrix(): void
    {
        $pla = Material::factory()->create();
        $silk = Material::factory()->create();
        $rosa = Color::factory()->create();
        $preto = Color::factory()->create();
        // Nao ha rosa em silk: a matriz da 3 e nao 4.
        $rosa->materials()->attach($pla);
        $preto->materials()->attach([$pla->id, $silk->id]);

        $this->writer()->tool(ProductCreateTool::class, [
            'name' => 'Vaso Ondulado',
            'tags' => ['Natal'],
            'variants' => [
                'color_ids' => [$rosa->id, $preto->id],
                'material_ids' => [$pla->id, $silk->id],
                'normal_price' => '24,90',
                'wholesale_price' => '15',
            ],
        ])->assertOk()->assertSee('com 3 variante(s)');

        $product = Product::query()->where('name', 'Vaso Ondulado')->sole();
        $this->assertSame('draft', $product->status);
        $this->assertSame(3, $product->variants()->count());
        $this->assertSame(2490, $product->variants()->value('price_cents'));
        $this->assertSame(['Natal'], $product->tags->pluck('name')->all());
    }

    public function test_the_backoffice_rules_apply_to_product_create(): void
    {
        $color = Color::factory()->create();
        $material = Material::factory()->create();
        $color->materials()->attach($material);

        // Promocao acima do preco normal: o after() do StoreProductRequest.
        $this->writer()->tool(ProductCreateTool::class, [
            'name' => 'Errado',
            'variants' => [
                'color_ids' => [$color->id],
                'material_ids' => [$material->id],
                'normal_price' => '10',
                'sale_price' => '12',
                'wholesale_price' => '5',
            ],
        ])->assertHasErrors();

        $this->assertDatabaseMissing('products', ['name' => 'Errado']);
    }

    public function test_the_description_is_purified(): void
    {
        $this->writer()->tool(ProductCreateTool::class, [
            'name' => 'Com HTML',
            'description' => '<p>Olá</p><script>alert(1)</script><img src=x onerror=alert(1)>',
        ])->assertOk();

        $description = (string) Product::query()->where('name', 'Com HTML')->value('description');

        $this->assertStringContainsString('<p>Olá</p>', $description);
        $this->assertStringNotContainsString('<script', $description);
        $this->assertStringNotContainsString('onerror', $description);
    }

    public function test_product_update_only_touches_what_was_sent(): void
    {
        $product = Product::factory()->create(['name' => 'Antigo', 'status' => 'draft', 'vat_rate' => 23, 'description' => '<p>Fica</p>']);

        $this->writer()->tool(ProductUpdateTool::class, ['id' => $product->id, 'status' => 'active'])
            ->assertOk();

        $product->refresh();
        $this->assertSame('active', $product->status);
        $this->assertSame('Antigo', $product->name);
        $this->assertSame('<p>Fica</p>', $product->description);

        $activity = McpActivity::query()->where('tool', 'product_update')->sole();
        $this->assertSame(['status' => 'draft', 'tags' => []], $activity->changes['before']);
    }

    public function test_archive_and_restore(): void
    {
        $product = Product::factory()->create(['status' => 'active']);

        $this->writer()->tool(ProductArchiveTool::class, ['id' => $product->id])->assertOk();
        $this->assertSame('archived', $product->refresh()->status);

        // Volta como rascunho, nunca direto a venda.
        $this->writer()->tool(ProductRestoreTool::class, ['id' => $product->id])->assertOk();
        $this->assertSame('draft', $product->refresh()->status);
    }

    // ── Variantes e precos ─────────────────────────────────────────────────

    public function test_a_small_price_change_goes_through(): void
    {
        $variant = $this->variant();

        $this->writer()->tool(VariantUpdateTool::class, ['variant_id' => $variant->id, 'normal_price' => '22,50'])
            ->assertOk();

        $this->assertSame(2250, $variant->refresh()->price_cents);

        $activity = McpActivity::query()->where('tool', 'variant_update')->sole();
        $this->assertSame('20.00', $activity->changes['before']['normal_price']);
        $this->assertSame('22.50', $activity->changes['after']['normal_price']);
    }

    public function test_a_drop_over_half_needs_confirmation(): void
    {
        $variant = $this->variant();

        $this->writer()->tool(VariantUpdateTool::class, ['variant_id' => $variant->id, 'normal_price' => '4', 'wholesale_price' => '2'])
            ->assertHasErrors()
            ->assertSee('confirm');

        $this->assertSame(2000, $variant->refresh()->price_cents);

        $this->writer()->tool(VariantUpdateTool::class, ['variant_id' => $variant->id, 'normal_price' => '4', 'wholesale_price' => '2', 'confirm' => true])
            ->assertOk();

        $this->assertSame(400, $variant->refresh()->price_cents);
    }

    public function test_a_zero_price_needs_confirmation(): void
    {
        $variant = $this->variant(['wholesale_price_cents' => null]);

        $this->writer()->tool(VariantUpdateTool::class, ['variant_id' => $variant->id, 'normal_price' => '0'])
            ->assertHasErrors()
            ->assertSee('0 €');
    }

    public function test_a_price_below_cost_needs_confirmation(): void
    {
        $material = Material::factory()->create(['price_per_kg_cents' => 3000]);
        $variant = $this->variant([
            'material_id' => $material->id,
            // Um quilo de filamento a 30 €/kg: so o plastico ja passa os 13 €.
            'filament_weight_grams' => 1000,
            'printing_time_minutes' => 600,
            'wholesale_price_cents' => null,
        ]);

        // 13 € fica dentro dos 50% de 20 € — so o custo o apanha.
        $this->writer()->tool(VariantUpdateTool::class, ['variant_id' => $variant->id, 'normal_price' => '13'])
            ->assertHasErrors()
            ->assertSee('abaixo do custo');
    }

    public function test_sale_price_can_be_set_and_removed(): void
    {
        $variant = $this->variant();

        $this->writer()->tool(VariantUpdateTool::class, ['variant_id' => $variant->id, 'sale_price' => '15'])->assertOk();
        $variant->refresh();
        $this->assertSame(1500, $variant->price_cents);
        $this->assertSame(2000, $variant->compare_at_cents);

        $this->writer()->tool(VariantUpdateTool::class, ['variant_id' => $variant->id, 'sale_price' => ''])->assertOk();
        $variant->refresh();
        $this->assertSame(2000, $variant->price_cents);
        $this->assertNull($variant->compare_at_cents);
    }

    public function test_the_backoffice_rules_apply_to_variant_update(): void
    {
        $variant = $this->variant();

        // Revenda acima do preco de venda: o after() do StoreVariantRequest.
        $this->writer()->tool(VariantUpdateTool::class, ['variant_id' => $variant->id, 'wholesale_price' => '30'])
            ->assertHasErrors()
            ->assertSee('revenda');

        $this->assertSame(1200, $variant->refresh()->wholesale_price_cents);
    }

    public function test_an_update_that_changes_nothing_writes_nothing(): void
    {
        $variant = $this->variant();
        $updatedAt = $variant->updated_at;

        $this->travel(1)->minutes();

        $this->writer()->tool(VariantUpdateTool::class, ['variant_id' => $variant->id, 'normal_price' => '20,00'])
            ->assertOk()
            ->assertSee('Nada mudou');

        $this->assertEquals($updatedAt, $variant->refresh()->updated_at);
        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_variant_update_never_touches_stock(): void
    {
        $variant = $this->variant(['stock' => 7]);

        $this->writer()->tool(VariantUpdateTool::class, ['variant_id' => $variant->id, 'stock' => 999, 'normal_price' => '21'])
            ->assertOk();

        $this->assertSame(7, $variant->refresh()->stock);
    }

    public function test_the_default_variant_is_changed_by_choosing_another(): void
    {
        $default = $this->variant(['is_default' => true]);
        $other = $this->variant(['product_id' => $default->product_id]);

        $this->writer()->tool(VariantUpdateTool::class, ['variant_id' => $default->id, 'is_default' => false])
            ->assertHasErrors();

        $this->writer()->tool(VariantArchiveTool::class, ['variant_id' => $default->id])->assertHasErrors();

        $this->writer()->tool(VariantSetDefaultTool::class, ['variant_id' => $other->id])->assertOk();
        $this->assertTrue($other->refresh()->is_default);
        $this->assertFalse($default->refresh()->is_default);

        $this->writer()->tool(VariantArchiveTool::class, ['variant_id' => $default->id])->assertOk();
        $this->assertFalse($default->refresh()->active);
    }

    public function test_variant_create_generates_the_sku_and_starts_at_zero_stock(): void
    {
        $product = Product::factory()->create(['slug' => 'caixa-ambar']);

        $this->writer()->tool(VariantCreateTool::class, [
            'product_id' => $product->id,
            'normal_price' => '19,90',
            'stock' => 50,
        ])->assertOk();

        $variant = $product->variants()->sole();
        $this->assertSame('CAIXA-AMBAR-1', $variant->sku);
        $this->assertSame(0, $variant->stock);
        $this->assertSame(1990, $variant->price_cents);
    }

    public function test_variant_create_checks_the_color_exists_in_the_material(): void
    {
        $product = Product::factory()->create();
        $pla = Material::factory()->create();
        $silk = Material::factory()->create(['name' => 'Silk']);
        $rosa = Color::factory()->create(['name' => 'Rosa']);
        $rosa->materials()->attach($pla);

        $this->writer()->tool(VariantCreateTool::class, [
            'product_id' => $product->id,
            'normal_price' => '10',
            'color_id' => $rosa->id,
            'material_id' => $silk->id,
        ])->assertHasErrors()->assertSee('Não tens Rosa em Silk');
    }

    // ── Lote de precos calculados ──────────────────────────────────────────

    public function test_calculated_prices_are_a_dry_run_unless_told_otherwise(): void
    {
        $material = Material::factory()->create(['price_per_kg_cents' => 2000]);
        $variant = $this->variant(['material_id' => $material->id, 'filament_weight_grams' => 50, 'printing_time_minutes' => 120]);
        $blank = $this->variant(['product_id' => $variant->product_id]);

        $this->writer()->tool(VariantsApplyCalculatedPricesTool::class, ['product_id' => $variant->product_id])
            ->assertOk()
            ->assertSee('"dry_run":true')
            ->assertSee('suggested');

        $this->assertSame(2000, $variant->refresh()->price_cents);

        $this->writer()->tool(VariantsApplyCalculatedPricesTool::class, ['product_id' => $variant->product_id, 'dry_run' => false])
            ->assertOk()
            ->assertSee('aplicados a 1');

        $this->assertNotSame(2000, $variant->refresh()->price_cents);
        $this->assertSame(2000, $blank->refresh()->price_cents);
    }

    public function test_calculated_prices_are_capped_at_fifty_variants(): void
    {
        $product = Product::factory()->create();

        $this->writer()->tool(VariantsApplyCalculatedPricesTool::class, [
            'product_id' => $product->id,
            'variant_ids' => range(1, 51),
        ])->assertHasErrors();
    }

    // ── Stock ──────────────────────────────────────────────────────────────

    public function test_stock_adjust_records_a_movement_in_the_users_name(): void
    {
        $variant = $this->variant(['stock' => 4]);

        $this->writer()->tool(StockAdjustTool::class, ['variant_id' => $variant->id, 'delta' => 5, 'note' => 'impressas 5'])
            ->assertOk()
            ->assertSee('4 → 9');

        $movement = StockMovement::query()->sole();
        $this->assertSame(5, $movement->delta);
        $this->assertSame('manual_adjust', $movement->reason);
        $this->assertSame($this->admin->id, $movement->created_by_user_id);
        $this->assertSame('MCP: impressas 5', $movement->note);
    }

    public function test_stock_adjust_by_absolute_value(): void
    {
        $variant = $this->variant(['stock' => 10]);

        $this->writer()->tool(StockAdjustTool::class, ['variant_id' => $variant->id, 'set_to' => 6, 'note' => 'contagem'])
            ->assertOk();

        $this->assertSame(6, $variant->refresh()->stock);
        $this->assertSame(-4, StockMovement::query()->sole()->delta);
    }

    public function test_stock_never_goes_below_what_is_reserved(): void
    {
        $variant = $this->variant(['stock' => 5, 'reserved_stock' => 4]);

        $this->writer()->tool(StockAdjustTool::class, ['variant_id' => $variant->id, 'delta' => -3, 'note' => 'partiu'])
            ->assertHasErrors()
            ->assertSee('reservado 4');

        $this->assertSame(5, $variant->refresh()->stock);
    }

    public function test_absurd_stock_changes_are_refused(): void
    {
        $variant = $this->variant(['stock' => 0]);

        $this->writer()->tool(StockAdjustTool::class, ['variant_id' => $variant->id, 'delta' => 5000, 'note' => 'x'])->assertHasErrors();
        $this->writer()->tool(StockAdjustTool::class, ['variant_id' => $variant->id, 'set_to' => 5000, 'note' => 'x'])->assertHasErrors();

        $this->assertSame(0, $variant->refresh()->stock);
    }

    public function test_writes_are_audited_with_arguments_and_before_after(): void
    {
        $variant = $this->variant(['stock' => 10]);

        $this->writer()->tool(StockAdjustTool::class, ['variant_id' => $variant->id, 'delta' => 1, 'note' => 'contagem'])->assertOk();

        $activity = McpActivity::query()->where('tool', 'stock_adjust')->sole();
        $this->assertSame(McpActivity::RESULT_OK, $activity->result);
        $this->assertSame(1, $activity->arguments['delta']);
        $this->assertSame(['stock' => 10], $activity->changes['before']);
        $this->assertSame(['stock' => 11], $activity->changes['after']);
    }

    public function test_refused_price_changes_are_audited_as_errors(): void
    {
        $variant = $this->variant(['wholesale_price_cents' => null]);

        $this->writer()->tool(VariantUpdateTool::class, ['variant_id' => $variant->id, 'normal_price' => '0'])->assertHasErrors();

        $this->assertDatabaseHas('mcp_activity', ['tool' => 'variant_update', 'result' => McpActivity::RESULT_ERROR]);
    }
}
