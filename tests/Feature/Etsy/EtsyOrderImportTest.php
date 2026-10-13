<?php

namespace Tests\Feature\Etsy;

use App\Alerts\AlertChannel;
use App\Etsy\EtsyListingSync;
use App\Etsy\EtsyOrderImporter;
use App\Jobs\SyncEtsyListing;
use App\Models\AlertState;
use App\Models\EtsyConnection;
use App\Models\EtsyReceipt;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Variant;
use App\Services\OrderService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Alerts\FakesDiscord;
use Tests\TestCase;

/**
 * As encomendas da Etsy a entrarem sozinhas. O que os testes guardam: uma
 * venda nunca entra duas vezes nem desce o stock duas vezes; nunca se perde
 * por faltar stock ou por uma linha nao casar; e do comprador so fica o que
 * e preciso para lhe mandar a peca.
 */
class EtsyOrderImportTest extends TestCase
{
    use FakesDiscord;
    use FakesEtsy;
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Mail::fake();

        $this->configureEtsy();
        $this->connectEtsy(['orders_since' => now()->subHour()]);
        $this->fakeEtsyShopConfig();

        // Vaso Espiral, a venda na Etsy: VASO-P (5 em stock, 9301 la) e
        // VASO-B (3 em stock, 9302 la).
        $this->product = $this->etsyPushedProduct('active');

        Queue::fake([SyncEtsyListing::class]);
    }

    /**
     * @return array{fetched: int, imported: int, updated: int, review: int, failed: int}
     */
    private function import(): array
    {
        return app(EtsyOrderImporter::class)->run();
    }

    private function stock(string $sku): int
    {
        return (int) Variant::query()->where('sku', $sku)->value('stock');
    }

    private function order(): Order
    {
        return Order::query()->where('sales_channel', 'etsy')->sole();
    }

    // ── Entrar ─────────────────────────────────────────────────────────────

    public function test_a_paid_receipt_becomes_an_order(): void
    {
        $this->fakeEtsyReceipts([$this->etsyReceipt()]);

        $result = $this->import();

        $this->assertSame(['fetched' => 1, 'imported' => 1, 'updated' => 0, 'review' => 0, 'failed' => 0], $result);

        $order = $this->order();
        $this->assertSame('etsy', $order->payment_method);
        $this->assertSame('paid', $order->payment_status);
        // Produto em stock: nao passa pela producao.
        $this->assertSame('ready_to_ship', $order->status);
        $this->assertSame('3001', $order->external_order_reference);
        $this->assertSame('Ana Maria Silva', $order->customer_name);
        $this->assertNull($order->user_id);
        $this->assertSame(4980, $order->subtotal_cents);
        $this->assertSame(450, $order->shipping_cents);
        $this->assertSame(0, $order->adjustment_cents);
        $this->assertSame(5430, $order->total_cents);
        $this->assertSame([
            'name' => 'Ana Maria Silva', 'line1' => 'Rua das Flores 12', 'line2' => '2.º Esq',
            'postalCode' => '4000-123', 'city' => 'Porto', 'country' => 'PT', 'phone' => null,
        ], $order->shipping_address);

        $item = $order->items()->sole();
        $this->assertSame(Variant::query()->where('sku', 'VASO-P')->value('id'), $item->variant_id);
        $this->assertSame('Vaso Espiral', $item->product_name);
        $this->assertSame(2, $item->qty);
        $this->assertSame(2490, $item->unit_price_cents);
        $this->assertSame(4980, $item->line_total_cents);
        $this->assertSame('not_required', $item->production_status);

        $this->assertSame(3, $this->stock('VASO-P'));
        $movement = StockMovement::query()->where('order_id', $order->id)->sole();
        $this->assertSame('etsy_order', $movement->reason);
        $this->assertSame(-2, $movement->delta);

        $receipt = EtsyReceipt::query()->sole();
        $this->assertSame(3001, $receipt->etsy_receipt_id);
        $this->assertSame($order->id, $receipt->order_id);
        $this->assertSame('imported', $receipt->status);
        $this->assertSame('paid', $receipt->etsy_status);
        $this->assertSame('Pode embrulhar para oferta?', $receipt->buyer_message);
        // O que a Etsy diz do pagamento: bruto, a taxa dela, e o que sobra.
        $this->assertSame(5430, $receipt->totals['gross_cents']);
        $this->assertSame(247, $receipt->totals['fees_cents']);
        $this->assertSame(5183, $receipt->totals['net_cents']);
    }

    public function test_the_email_of_the_buyer_is_never_kept(): void
    {
        $this->fakeEtsyReceipts([$this->etsyReceipt()]);

        $this->import();

        $this->assertNull($this->order()->email);
        $this->assertNull($this->order()->phone);

        // Em lado nenhum: nem na encomenda, nem no recibo, nem no historico.
        foreach (['orders', 'etsy_receipts', 'order_status_histories', 'order_items'] as $table) {
            $this->assertStringNotContainsString('comprador@example.com', (string) json_encode(DB::table($table)->get()), $table);
        }
    }

    public function test_importing_twice_changes_nothing(): void
    {
        $this->fakeEtsyReceipts([$this->etsyReceipt()]);
        $this->import();

        $result = $this->import();

        $this->assertSame(['fetched' => 1, 'imported' => 0, 'updated' => 0, 'review' => 0, 'failed' => 0], $result);
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(3, $this->stock('VASO-P'));
        $this->assertSame(1, StockMovement::query()->where('reason', 'etsy_order')->count());
    }

    public function test_the_cursor_starts_at_the_connection_and_then_follows_etsy(): void
    {
        // A hora gravada na ligacao, e nao "agora menos uma hora": entre o
        // setUp e aqui pode ter passado um segundo.
        $since = EtsyConnection::query()->sole()->orders_since?->timestamp;
        $receipt = $this->etsyReceipt(['update_timestamp' => now()->addMinutes(3)->timestamp]);
        $this->fakeEtsyReceipts([$receipt]);

        $this->import();

        parse_str((string) parse_url($this->etsyRequests('/receipts?')->first()->url(), PHP_URL_QUERY), $first);
        $this->assertSame((string) $since, $first['min_last_modified']);
        $this->assertSame('updated', $first['sort_on']);
        $this->assertSame((string) $receipt['update_timestamp'], AlertState::watermark(EtsyOrderImporter::CURSOR));

        $this->fakeEtsyReceipts([$receipt]);
        $this->import();

        parse_str((string) parse_url($this->etsyRequests('/receipts?')->first()->url(), PHP_URL_QUERY), $second);
        $this->assertSame((string) $receipt['update_timestamp'], $second['min_last_modified']);
    }

    public function test_every_page_is_read(): void
    {
        config(['etsy.orders.page_size' => 2]);
        $this->fakeEtsyReceipts([
            $this->etsyReceipt(['receipt_id' => 3001, 'update_timestamp' => now()->timestamp]),
            $this->etsyReceipt(['receipt_id' => 3002, 'update_timestamp' => now()->timestamp + 1]),
            $this->etsyReceipt(['receipt_id' => 3003, 'update_timestamp' => now()->timestamp + 2]),
        ]);
        Variant::query()->where('sku', 'VASO-P')->update(['stock' => 10]);

        $result = $this->import();

        $this->assertSame(3, $result['imported']);
        $this->assertSame(3, Order::query()->count());
        $this->assertCount(2, $this->etsyRequests('/receipts?'));
    }

    public function test_receipts_from_before_the_connection_are_left_alone(): void
    {
        // Vendida antes de a loja ser ligada, mexida depois (foi enviada): o
        // stock dela ja foi tratado a mao.
        $this->fakeEtsyReceipts([$this->etsyReceipt(['create_timestamp' => now()->subDay()->timestamp])]);

        $result = $this->import();

        $this->assertSame(0, $result['imported']);
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(5, $this->stock('VASO-P'));
    }

    public function test_an_unpaid_receipt_waits_until_it_is_paid(): void
    {
        $this->fakeEtsyReceipts([$this->etsyReceipt(['is_paid' => false, 'status' => 'open'])]);
        $this->import();

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, EtsyReceipt::query()->count());

        $this->fakeEtsyReceipts([$this->etsyReceipt(['update_timestamp' => now()->addMinute()->timestamp])]);
        $this->import();

        $this->assertSame('paid', $this->order()->payment_status);
    }

    // ── O que nao bate certo ───────────────────────────────────────────────

    public function test_a_sale_is_never_lost_for_lack_of_stock(): void
    {
        Variant::query()->where('sku', 'VASO-P')->update(['stock' => 1]);
        $this->fakeDiscord();
        $this->fakeEtsyReceipts([$this->etsyReceipt()]);

        $result = $this->import();

        // A Etsy ja a vendeu e ja cobrou: a encomenda entra, marcada. A
        // unidade que havia e dela: sai da prateleira, para nao ser vendida
        // outra vez.
        $this->assertSame(1, $result['imported']);
        $order = $this->order();
        $this->assertTrue($order->stock_issue);
        $this->assertSame(0, $this->stock('VASO-P'));
        $this->assertSame(-1, (int) StockMovement::query()->where('order_id', $order->id)->sum('delta'));
        $this->assertAlerted(AlertChannel::ORDERS, 'stock');
    }

    public function test_a_line_that_matches_nothing_enters_as_a_free_line_and_asks_for_review(): void
    {
        $receipt = $this->etsyReceipt();
        $receipt['transactions'][0] = [...$receipt['transactions'][0], 'sku' => 'NAO-EXISTE', 'product_id' => 999, 'title' => 'Custom Vase — special'];
        $this->fakeDiscord();
        $this->fakeEtsyReceipts([$receipt]);

        $result = $this->import();

        $this->assertSame(1, $result['review']);

        $item = $this->order()->items()->sole();
        $this->assertNull($item->variant_id);
        $this->assertSame('Custom Vase — special', $item->product_name);
        $this->assertSame('NAO-EXISTE', $item->sku);
        // Nao se sabe o que e: vai para a producao, para alguem olhar.
        $this->assertSame('awaiting_production', $item->production_status);
        $this->assertSame('in_production', $this->order()->status);

        $row = EtsyReceipt::query()->sole();
        $this->assertSame('needs_review', $row->status);
        $this->assertSame('NAO-EXISTE', $row->unmatched_lines[0]['sku']);
        $this->assertSame(5, $this->stock('VASO-P'));
    }

    public function test_without_a_reference_the_line_matches_by_what_etsy_calls_the_variation(): void
    {
        $receipt = $this->etsyReceipt();
        $receipt['transactions'][0] = [...$receipt['transactions'][0], 'sku' => null, 'product_id' => 9302, 'quantity' => 1];
        $this->fakeEtsyReceipts([$receipt]);

        $this->import();

        $this->assertSame(Variant::query()->where('sku', 'VASO-B')->value('id'), $this->order()->items()->sole()->variant_id);
        $this->assertSame(2, $this->stock('VASO-B'));
    }

    public function test_a_discount_lowers_the_total_and_the_tax_etsy_collects_stays_out(): void
    {
        $money = fn (int $cents): array => ['amount' => $cents, 'divisor' => 100, 'currency_code' => 'EUR'];
        $this->fakeEtsyReceipts([$this->etsyReceipt([
            'discount_amt' => $money(500),
            'total_tax_cost' => $money(310),
            'grandtotal' => $money(5240),
        ])]);

        $this->import();

        $order = $this->order();
        $this->assertSame(4980, $order->subtotal_cents);
        $this->assertSame(-500, $order->adjustment_cents);
        $this->assertStringContainsString('Etsy', (string) $order->adjustment_reason);
        // 49,80 + 4,50 - 5,00. O imposto que a Etsy cobra e entrega nao e nosso.
        $this->assertSame(4930, $order->total_cents);
        $this->assertSame(310, EtsyReceipt::query()->sole()->totals['tax_cents']);
    }

    public function test_a_receipt_in_another_currency_is_not_guessed(): void
    {
        $this->fakeDiscord();
        $this->fakeEtsyReceipts([$this->etsyReceipt(['currency' => 'USD'])]);

        $result = $this->import();

        $this->assertSame(1, $result['review']);
        $this->assertSame(0, Order::query()->count());

        $row = EtsyReceipt::query()->sole();
        $this->assertSame('needs_review', $row->status);
        $this->assertNull($row->order_id);
        $this->assertStringContainsString('USD', (string) $row->last_error);
        $this->assertAlerted(AlertChannel::ORDERS, 'Etsy');
    }

    public function test_a_receipt_with_nothing_in_it_fails_without_stopping_the_others(): void
    {
        $this->fakeEtsyReceipts([
            $this->etsyReceipt(['receipt_id' => 3001, 'transactions' => []]),
            $this->etsyReceipt(['receipt_id' => 3002]),
        ]);

        $result = $this->import();

        $this->assertSame(1, $result['failed']);
        $this->assertSame(1, $result['imported']);
        $this->assertSame('failed', EtsyReceipt::query()->where('etsy_receipt_id', 3001)->sole()->status);
        $this->assertSame('3002', $this->order()->external_order_reference);
    }

    public function test_the_payment_being_unreadable_does_not_hold_the_order(): void
    {
        $this->fakeEtsyReceipts([$this->etsyReceipt()], ['payments' => Http::response(['error' => 'Server error'], 500)]);

        $this->import();

        $this->assertSame('paid', $this->order()->payment_status);
        $this->assertArrayNotHasKey('fees_cents', EtsyReceipt::query()->sole()->totals);
    }

    public function test_a_made_to_order_product_goes_to_production(): void
    {
        $this->product->update(['fulfillment_mode' => 'made_to_order']);
        $this->fakeEtsyReceipts([$this->etsyReceipt()]);

        $this->import();

        $this->assertSame('in_production', $this->order()->status);
        $this->assertSame('awaiting_production', $this->order()->items()->sole()->production_status);
        $this->assertSame(5, $this->stock('VASO-P'));
    }

    public function test_what_the_buyer_chose_and_wrote_stays_on_the_line(): void
    {
        $receipt = $this->etsyReceipt();
        $receipt['transactions'][0]['variations'][] = [
            'property_id' => 54, 'value_id' => null, 'formatted_name' => 'Personalização', 'formatted_value' => 'Para a avó Rosa', 'question_id' => 1,
        ];
        $this->fakeEtsyReceipts([$receipt]);

        $this->import();

        $this->assertSame(['Cor' => 'Preto', 'Personalização' => 'Para a avó Rosa'], $this->order()->items()->sole()->personalization);
    }

    public function test_the_order_of_a_deleted_product_still_comes_in(): void
    {
        // Apagado ca com o anuncio ainda la (a Etsy estava em baixo): a
        // venda aconteceu, e a variante continua a existir.
        $this->product->deleteQuietly();
        $this->fakeEtsyReceipts([$this->etsyReceipt()]);

        $this->import();

        $this->assertSame(3, $this->stock('VASO-P'));
        $this->assertNotNull($this->order()->items()->sole()->variant_id);
    }

    // ── Quem fica a saber ──────────────────────────────────────────────────

    public function test_no_email_leaves_and_discord_never_sees_the_buyer(): void
    {
        $this->fakeDiscord();
        $this->fakeEtsyReceipts([$this->etsyReceipt()]);

        $this->import();

        // A Etsy ja avisa o comprador; e os termos dela nao deixam que
        // sejamos nos a faze-lo.
        Mail::assertNothingOutgoing();

        $this->assertAlerted(AlertChannel::ORDERS, 'Nova encomenda');

        $sent = Http::recorded()
            ->filter(fn (array $pair): bool => str_contains($pair[0]->url(), 'discord.test'))
            ->map(fn (array $pair): string => (string) json_encode($pair[0]->data(), JSON_UNESCAPED_UNICODE))
            ->implode("\n");

        foreach (['Ana', 'Silva', 'Rua das Flores', '4000-123', 'comprador@', 'embrulhar'] as $personal) {
            $this->assertStringNotContainsString($personal, $sent);
        }
    }

    // ── Depois de entrar ───────────────────────────────────────────────────

    public function test_a_cancellation_on_etsy_cancels_here_and_gives_the_stock_back(): void
    {
        $this->fakeEtsyReceipts([$this->etsyReceipt()]);
        $this->import();
        $this->assertSame(3, $this->stock('VASO-P'));

        $this->fakeEtsyReceipts([$this->etsyReceipt(['status' => 'canceled', 'update_timestamp' => now()->addMinute()->timestamp])]);
        $result = $this->import();

        $this->assertSame(1, $result['updated']);
        $this->assertSame('cancelled', $this->order()->status);
        $this->assertSame(5, $this->stock('VASO-P'));
        $this->assertSame('canceled', EtsyReceipt::query()->sole()->etsy_status);
    }

    public function test_a_cancellation_after_shipping_only_asks_for_a_look(): void
    {
        $this->fakeEtsyReceipts([$this->etsyReceipt()]);
        $this->import();
        app(OrderService::class)->transitionOrder($this->order(), 'shipped');

        $this->fakeDiscord();
        $this->fakeEtsyReceipts([$this->etsyReceipt(['status' => 'canceled', 'update_timestamp' => now()->addMinute()->timestamp])]);
        $this->import();

        // A peca ja saiu: o stock nao volta sozinho.
        $this->assertSame('shipped', $this->order()->status);
        $this->assertSame(3, $this->stock('VASO-P'));
        $this->assertSame('needs_review', EtsyReceipt::query()->sole()->status);
        $this->assertAlerted(AlertChannel::ORDERS, 'Etsy');
    }

    public function test_a_full_refund_before_shipping_cancels_and_gives_the_stock_back(): void
    {
        $this->fakeEtsyReceipts([$this->etsyReceipt()]);
        $this->import();

        $this->fakeEtsyReceipts([$this->etsyReceipt(['status' => 'fully refunded', 'update_timestamp' => now()->addMinute()->timestamp])]);
        $this->import();

        // Devolvido o dinheiro todo sem a peca ter saido: e um cancelamento,
        // e a peca volta a prateleira.
        $this->assertSame('cancelled', $this->order()->status);
        $this->assertSame(5, $this->stock('VASO-P'));
    }

    public function test_a_partial_refund_asks_for_a_look_and_does_not_stop_the_order(): void
    {
        $this->fakeDiscord();
        $this->fakeEtsyReceipts([$this->etsyReceipt()]);
        $this->import();

        $this->fakeEtsyReceipts([$this->etsyReceipt(['status' => 'partially refunded', 'update_timestamp' => now()->addMinute()->timestamp])]);
        $this->import();

        // Um desconto dado depois: a peca vai na mesma. Marca-la como "nao
        // paga" parava-a a meio da producao.
        $order = $this->order();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('ready_to_ship', $order->status);
        $this->assertSame('needs_review', EtsyReceipt::query()->sole()->status);
        $this->assertSame('partially refunded', EtsyReceipt::query()->sole()->etsy_status);
        $this->assertAlerted(AlertChannel::ORDERS, 'Etsy');
    }

    public function test_shipped_on_etsy_is_mirrored_here(): void
    {
        $this->fakeEtsyReceipts([$this->etsyReceipt()]);
        $this->import();

        $this->fakeEtsyReceipts([$this->etsyReceipt([
            'is_shipped' => true,
            'status' => 'completed',
            'update_timestamp' => now()->addMinute()->timestamp,
            'shipments' => [['receipt_shipping_id' => 1, 'carrier_name' => 'ctt', 'tracking_code' => 'RR123456789PT', 'shipment_notification_timestamp' => now()->timestamp]],
        ])]);
        $this->import();

        $order = $this->order();
        $this->assertSame('shipped', $order->status);
        $this->assertSame('RR123456789PT', $order->tracking_number);
        $this->assertSame('ctt', $order->shipping_method_name);
        $this->assertNotNull(EtsyReceipt::query()->sole()->shipped_on_etsy_at);
        Mail::assertNothingOutgoing();
    }

    // ── O stock que vai para a Etsy ────────────────────────────────────────

    public function test_before_sending_stock_to_a_listing_on_sale_the_orders_come_in_first(): void
    {
        // A Etsy vendeu duas e ainda nao sabemos. Sem importar primeiro, o
        // envio repunha la as duas que ela ja vendeu.
        $this->fakeEtsyListingWrites();
        $this->fakeEtsyReceipts([$this->etsyReceipt()]);
        $this->product->update(['name' => 'Vaso Espiral XL']);

        app(EtsyListingSync::class)->push($this->product->fresh());

        $this->assertSame('3001', $this->order()->external_order_reference);
        $inventory = $this->etsyRequests('/inventory')->filter(fn (Request $r): bool => $r->method() === 'PUT')->last();
        $this->assertSame(3, $inventory['products'][0]['offerings'][0]['quantity']);
    }

    public function test_even_what_we_think_is_a_draft_asks_for_the_orders_first(): void
    {
        // O estado que ca esta pode ter horas: publicado a mao na Etsy, um
        // "rascunho" ja pode ter vendido.
        $this->product->etsyListing()->update(['state' => 'draft']);
        $this->fakeEtsyListingWrites();
        $this->fakeEtsyReceipts([$this->etsyReceipt()]);

        app(EtsyListingSync::class)->push($this->product->fresh());

        $this->assertSame('3001', $this->order()->external_order_reference);
    }

    public function test_a_product_that_is_not_on_etsy_yet_asks_nothing(): void
    {
        $this->product->etsyListing()->update(['etsy_listing_id' => null, 'state' => null]);
        $this->fakeEtsyListingWrites();
        $this->fakeEtsyReceipts([$this->etsyReceipt()]);

        app(EtsyListingSync::class)->push($this->product->fresh());

        $this->assertCount(0, $this->etsyRequests('/receipts'));
    }

    // ── O comando ──────────────────────────────────────────────────────────

    public function test_the_command_imports_and_is_on_the_schedule(): void
    {
        $this->fakeEtsyReceipts([$this->etsyReceipt()]);

        $this->artisan('etsy:import-orders')->assertSuccessful();

        $this->assertSame(1, Order::query()->count());

        $commands = collect(app(Schedule::class)->events())->map(fn ($event): string => (string) $event->command);
        $this->assertTrue($commands->contains(fn (string $command): bool => str_ends_with($command, 'etsy:import-orders')));
    }

    public function test_the_command_is_quiet_when_etsy_is_off(): void
    {
        config(['etsy.enabled' => false]);
        $this->fakeEtsyReceipts([$this->etsyReceipt()]);

        $this->artisan('etsy:import-orders')->assertSuccessful();

        $this->assertSame(0, Order::query()->count());
        $this->assertCount(0, $this->etsyRequests('/receipts'));
    }

    public function test_etsy_being_down_warns_once_and_does_not_fail_the_scheduler(): void
    {
        $this->fakeDiscord();
        $this->fakeEtsyReceipts([], ['list' => Http::response(['error' => 'Service unavailable'], 503)]);

        $this->artisan('etsy:import-orders')->assertSuccessful();
        $this->artisan('etsy:import-orders')->assertSuccessful();

        // De 5 em 5 minutos, o mesmo aviso era ruido.
        $this->assertCount(1, array_filter($this->alertTitles(AlertChannel::SYSTEM), fn (string $title): bool => str_contains($title, 'Etsy')));
    }
}
