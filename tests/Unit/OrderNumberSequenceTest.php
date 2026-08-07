<?php

namespace Tests\Unit;

use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrderNumberSequenceTest extends TestCase
{
    use RefreshDatabase;

    private OrderService $orders;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orders = app(OrderService::class);
    }

    public function test_numbers_are_sequential_and_unique(): void
    {
        $numbers = [];

        for ($i = 0; $i < 50; $i++) {
            $numbers[] = $this->orders->nextOrderNumber();
        }

        $this->assertCount(50, array_unique($numbers));
        $this->assertSame(now()->year.'-0001', $numbers[0]);
        $this->assertSame(now()->year.'-0050', $numbers[49]);
    }

    public function test_a_new_year_starts_its_own_row_at_one(): void
    {
        $this->orders->nextOrderNumber();

        $this->travel(1)->years();

        $number = $this->orders->nextOrderNumber();

        $this->assertSame(now()->year.'-0001', $number);
        $this->assertDatabaseCount('order_sequences', 2);
    }

    public function test_the_counter_is_incremented_in_a_single_statement(): void
    {
        // Ler-e-depois-escrever daria numeros repetidos sob concorrencia; em
        // SQLite o lockForUpdate() e no-op, por isso o incremento tem de
        // acontecer dentro do proprio UPDATE.
        $this->orders->nextOrderNumber();

        DB::table('order_sequences')->update(['last_number' => 41]);

        $this->assertSame(now()->year.'-0042', $this->orders->nextOrderNumber());
    }
}
