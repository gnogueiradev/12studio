<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\StudioServer;
use App\Mcp\Tools\Read\ProductGetTool;
use App\Mcp\Tools\Read\ProductsListTool;
use App\Mcp\Tools\Write\ProductDeleteTool;
use App\Mcp\Tools\Write\ProductRestoreTool;
use App\Mcp\Tools\Write\StockAdjustTool;
use App\Mcp\Tools\Write\VariantArchiveTool;
use App\Mcp\Tools\Write\VariantSetDefaultTool;
use App\Mcp\Tools\Write\VariantUpdateTool;
use App\Models\McpActivity;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\Testing\PendingTestResponse;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Apagar um produto pelo Claude: a reciclagem do backoffice, com a pergunta
 * antes. O que ja existia (arquivar, restaurar) continua igual.
 */
class ProductDeleteToolTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

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

    private function deleted(Product $product): Product
    {
        $this->writer()->tool(ProductDeleteTool::class, ['id' => $product->id, 'confirm' => true])->assertOk();

        return $product;
    }

    public function test_it_asks_before_deleting(): void
    {
        $product = Product::factory()->create(['name' => 'Vaso Espiral']);
        Variant::factory()->for($product)->count(2)->create();

        // Sem `confirm` nada acontece: a resposta diz o que esta em jogo para
        // o Claude o mostrar ao dono.
        $this->writer()->tool(ProductDeleteTool::class, ['id' => $product->id])
            ->assertHasErrors()
            ->assertSee('Vaso Espiral')
            ->assertSee('2 variantes')
            ->assertSee('"confirm": true');

        $this->assertFalse($product->refresh()->trashed());
    }

    public function test_confirmed_it_takes_the_product_out_of_sight(): void
    {
        $product = $this->deleted(Product::factory()->create(['name' => 'Vaso Espiral', 'status' => 'active']));

        $row = Product::withTrashed()->findOrFail($product->id);
        $this->assertTrue($row->trashed());
        $this->assertSame('archived', $row->status);

        $this->reader()->tool(ProductsListTool::class, ['status' => 'archived'])
            ->assertOk()
            ->assertDontSee('Vaso Espiral');

        $this->reader()->tool(ProductGetTool::class, ['id' => $product->id])
            ->assertHasErrors();
    }

    public function test_the_deleted_ones_can_be_listed_on_request(): void
    {
        $this->deleted(Product::factory()->create(['name' => 'Vaso Espiral']));
        Product::factory()->create(['name' => 'Suporte']);

        // E assim que o Claude encontra o id para o product_restore.
        $this->reader()->tool(ProductsListTool::class, ['only_deleted' => true])
            ->assertOk()
            ->assertSee('Vaso Espiral')
            ->assertDontSee('Suporte');
    }

    public function test_restore_recovers_a_deleted_product_as_a_draft(): void
    {
        $product = $this->deleted(Product::factory()->create(['status' => 'active']));

        $this->writer()->tool(ProductRestoreTool::class, ['id' => $product->id])->assertOk();

        $product->refresh();
        $this->assertFalse($product->trashed());
        $this->assertSame('draft', $product->status);
    }

    public function test_the_deletion_leaves_a_trail(): void
    {
        $product = $this->deleted(Product::factory()->create(['status' => 'active']));

        $activity = McpActivity::query()->where('tool', 'product_delete')->sole();

        $this->assertSame(McpActivity::RESULT_OK, $activity->result);
        $this->assertSame($product->id, $activity->changes['product_id']);
        $this->assertSame(['status' => 'active', 'deleted' => false], $activity->changes['before']);
        $this->assertSame(['status' => 'archived', 'deleted' => true], $activity->changes['after']);
    }

    public function test_the_variants_of_a_deleted_product_are_frozen(): void
    {
        $product = Product::factory()->create();
        $variant = Variant::factory()->for($product)->stock(5)->create(['price_cents' => 2000, 'is_default' => false]);
        $this->deleted($product);

        // A variante continua a existir por id. Mexer-lhe no preco ou no stock
        // era mexer num produto que o dono ja nao ve.
        $calls = [
            [VariantUpdateTool::class, ['variant_id' => $variant->id, 'normal_price' => '21']],
            [StockAdjustTool::class, ['variant_id' => $variant->id, 'delta' => 3, 'note' => 'Reposicao']],
            [VariantArchiveTool::class, ['variant_id' => $variant->id]],
            [VariantSetDefaultTool::class, ['variant_id' => $variant->id]],
        ];

        foreach ($calls as [$tool, $arguments]) {
            $this->writer()->tool($tool, $arguments)
                ->assertHasErrors()
                ->assertSee('product_restore');
        }

        $variant->refresh();
        $this->assertSame(2000, $variant->price_cents);
        $this->assertSame(5, $variant->stock);
        $this->assertTrue($variant->active);
        $this->assertFalse($variant->is_default);
    }

    public function test_a_read_only_key_cannot_delete(): void
    {
        $product = Product::factory()->create();

        $this->reader()->tool(ProductDeleteTool::class, ['id' => $product->id, 'confirm' => true])
            ->assertNotRegistered();

        $this->assertFalse($product->refresh()->trashed());
    }

    public function test_an_unknown_product_is_not_found(): void
    {
        $this->writer()->tool(ProductDeleteTool::class, ['id' => 999, 'confirm' => true])
            ->assertHasErrors(['Produto não encontrado.']);
    }
}
