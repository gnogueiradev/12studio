<?php

namespace Tests\Feature\Alerts;

use App\Alerts\AlertChannel;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockAlertsTest extends TestCase
{
    use FakesDiscord;
    use RefreshDatabase;

    private StockService $stock;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeDiscord();
        $this->stock = app(StockService::class);
        $this->admin = User::factory()->admin()->create(['name' => 'Gonçalo']);
    }

    private function variant(int $stock, int $threshold = 3): Variant
    {
        $product = Product::factory()->create(['name' => 'Vaso Geo']);

        return Variant::factory()->stock($stock)->create([
            'product_id' => $product->id,
            'size_label' => 'M',
            'low_stock_threshold' => $threshold,
        ]);
    }

    public function test_a_manual_adjustment_shows_before_and_after(): void
    {
        $variant = $this->variant(10);

        $this->stock->setAbsolute($variant, 14, 'manual_adjust', $this->admin, 'Contagem de inventário');

        $text = $this->embedText($this->alertEmbed(AlertChannel::STOCK, 'Stock ajustado — Vaso Geo (M)'));
        $this->assertStringContainsString('10 → 14 (+4)', $text);
        $this->assertStringContainsString('Contagem de inventário', $text);
        $this->assertStringContainsString('Gonçalo', $text);
    }

    public function test_crossing_the_threshold_warns_once(): void
    {
        $variant = $this->variant(5, threshold: 3);

        $this->stock->decrement($variant, 1, 'sale');
        $this->assertSame([], $this->alertTitles(AlertChannel::STOCK));

        $this->stock->decrement($variant, 1, 'sale');
        $this->assertAlerted(AlertChannel::STOCK, 'Stock baixo — Vaso Geo (M)');

        // Ja abaixo do limiar: vender mais uma nao volta a avisar.
        $this->stock->decrement($variant, 1, 'sale');
        $this->assertCount(1, $this->alertTitles(AlertChannel::STOCK));
    }

    public function test_running_out_is_its_own_alert(): void
    {
        $variant = $this->variant(2, threshold: 0);

        $this->stock->decrement($variant, 2, 'sale');

        $this->assertAlerted(AlertChannel::STOCK, 'Sem stock — Vaso Geo (M)');
        $this->assertNotAlerted(AlertChannel::STOCK, 'Stock baixo');
    }

    public function test_a_sale_is_not_a_manual_adjustment(): void
    {
        $variant = $this->variant(20);

        $this->stock->decrement($variant, 1, 'sale');

        $this->assertSame([], $this->alertTitles(AlertChannel::STOCK));
    }

    public function test_archived_products_do_not_alarm(): void
    {
        $variant = $this->variant(4);
        $variant->product->update(['status' => 'archived']);

        $this->stock->decrement($variant->refresh(), 2, 'sale');

        $this->assertSame([], $this->alertTitles(AlertChannel::STOCK));
    }
}
