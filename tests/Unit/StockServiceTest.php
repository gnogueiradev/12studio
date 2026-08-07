<?php

namespace Tests\Unit;

use App\Models\User;
use App\Models\Variant;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockServiceTest extends TestCase
{
    use RefreshDatabase;

    private StockService $stock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stock = app(StockService::class);
    }

    public function test_decrement_records_a_movement(): void
    {
        $variant = Variant::factory()->stock(10)->create();
        $admin = User::factory()->admin()->create();

        $this->assertTrue(
            $this->stock->decrement($variant, 3, 'manual_order', null, $admin)
        );

        $this->assertSame(7, $variant->refresh()->stock);
        $this->assertDatabaseHas('stock_movements', [
            'variant_id' => $variant->id,
            'delta' => -3,
            'reason' => 'manual_order',
            'created_by_user_id' => $admin->id,
        ]);
    }

    public function test_decrement_fails_without_writing_anything_when_stock_is_short(): void
    {
        $variant = Variant::factory()->stock(2)->create();

        $this->assertFalse($this->stock->decrement($variant, 3, 'manual_order'));

        $this->assertSame(2, $variant->refresh()->stock);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_reserved_units_are_not_available_to_sell(): void
    {
        // stock 5, reservado 4 -> disponivel 1: pedir 2 tem de falhar.
        $variant = Variant::factory()->stock(5)->create(['reserved_stock' => 4]);

        $this->assertFalse($this->stock->decrement($variant, 2, 'manual_order'));
        $this->assertTrue($this->stock->decrement($variant, 1, 'manual_order'));
    }

    public function test_set_absolute_writes_the_difference_as_one_movement(): void
    {
        $variant = Variant::factory()->stock(10)->create();

        $this->assertTrue($this->stock->setAbsolute($variant, 4));

        $this->assertSame(4, $variant->refresh()->stock);
        $this->assertDatabaseHas('stock_movements', [
            'variant_id' => $variant->id,
            'delta' => -6,
            'reason' => 'manual_adjust',
        ]);
    }

    public function test_set_absolute_to_the_same_value_writes_nothing(): void
    {
        $variant = Variant::factory()->stock(10)->create();

        $this->assertTrue($this->stock->setAbsolute($variant, 10));

        $this->assertDatabaseCount('stock_movements', 0);
    }
}
