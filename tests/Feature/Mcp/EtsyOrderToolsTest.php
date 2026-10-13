<?php

namespace Tests\Feature\Mcp;

use App\Etsy\EtsyOrderImporter;
use App\Jobs\SyncEtsyListing;
use App\Mcp\Servers\StudioServer;
use App\Mcp\Tools\Etsy\EtsyLedgerListTool;
use App\Mcp\Tools\Etsy\EtsyOrdersImportTool;
use App\Mcp\Tools\Etsy\EtsyPaymentsListTool;
use App\Mcp\Tools\Etsy\EtsyReceiptGetTool;
use App\Mcp\Tools\Etsy\EtsyReceiptsListTool;
use App\Mcp\Tools\Etsy\EtsyTransactionsListTool;
use App\Mcp\Tools\Read\OrderGetTool;
use App\Mcp\Tools\Read\OrdersListTool;
use App\Mcp\Tools\Write\Orders\OrderCreateManualTool;
use App\Models\Order;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\Fluent\AssertableJson;
use Laravel\Mcp\Server\Testing\PendingTestResponse;
use Laravel\Passport\Passport;
use Tests\Feature\Etsy\FakesEtsy;
use Tests\TestCase;

/**
 * Fase 4 no MCP: as encomendas da Etsy — importa-las, e ler os recibos, as
 * linhas, os pagamentos e o extrato diretamente de la.
 *
 * O que sai daqui vai para a Anthropic, e os termos da Etsy nao deixam
 * passar dados de compradores a terceiros sem necessidade: nome curto,
 * localidade e pais; nunca o email nem a rua.
 */
class EtsyOrderToolsTest extends TestCase
{
    use FakesEtsy;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Mail::fake();

        $this->configureEtsy();
        $this->connectEtsy(['orders_since' => now()->subHour()]);
        $this->fakeEtsyShopConfig();
        $this->etsyPushedProduct('active');

        Queue::fake([SyncEtsyListing::class]);

        $this->admin = User::factory()->admin()->create();
    }

    private function reader(): PendingTestResponse
    {
        Passport::actingAs($this->admin, ['mcp:read']);

        return StudioServer::actingAs($this->admin);
    }

    private function writer(): PendingTestResponse
    {
        Passport::actingAs($this->admin, ['mcp:read', 'mcp:write']);

        return StudioServer::actingAs($this->admin);
    }

    /**
     * @return array<string, mixed>
     */
    private function queryOf(string $needle): array
    {
        parse_str((string) parse_url($this->etsyRequests($needle)->last()->url(), PHP_URL_QUERY), $query);

        return $query;
    }

    // ── etsy_orders_import ─────────────────────────────────────────────────

    public function test_importing_on_request_brings_the_orders_and_says_how_many(): void
    {
        $this->fakeEtsyReceipts([$this->etsyReceipt()]);

        $this->reader()->tool(EtsyOrdersImportTool::class)->assertNotRegistered();

        $this->writer()->tool(EtsyOrdersImportTool::class)
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('imported', 1)
                ->where('needs_review', 0)
                ->etc());

        $this->assertSame(1, Order::query()->where('sales_channel', 'etsy')->count());
    }

    // ── etsy_receipts_list ─────────────────────────────────────────────────

    public function test_the_receipts_come_straight_from_etsy_with_the_filters_asked(): void
    {
        $this->fakeEtsyReceipts([$this->etsyReceipt()]);
        app(EtsyOrderImporter::class)->run();
        $this->fakeEtsyReceipts([$this->etsyReceipt()]);

        $this->reader()->tool(EtsyReceiptsListTool::class, ['was_paid' => true, 'was_shipped' => false, 'min_created' => '2026-10-01'])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('receipts.0.receipt_id', 3001)
                ->where('receipts.0.status', 'paid')
                ->where('receipts.0.total.cents', 5430)
                // Ja importada: diz que encomenda e, para nao a registar a mao.
                ->where('receipts.0.order_number', Order::query()->sole()->order_number)
                ->etc());

        $query = $this->queryOf('/receipts?');
        $this->assertSame('true', $query['was_paid']);
        $this->assertSame('false', $query['was_shipped']);
        $this->assertSame((string) Carbon::parse('2026-10-01')->startOfDay()->getTimestamp(), $query['min_created']);
    }

    public function test_what_identifies_the_buyer_stays_out_of_the_answer(): void
    {
        $this->fakeEtsyReceipts([$this->etsyReceipt()]);

        foreach ([
            $this->reader()->tool(EtsyReceiptsListTool::class),
            $this->reader()->tool(EtsyReceiptGetTool::class, ['receipt_id' => 3001]),
        ] as $response) {
            $response->assertOk()
                ->assertDontSee('comprador@example.com')
                ->assertDontSee('Rua das Flores')
                ->assertDontSee('4000-123')
                ->assertDontSee('Ana Maria Silva')
                ->assertDontSee('77001');
        }
    }

    // ── etsy_receipt_get ───────────────────────────────────────────────────

    public function test_a_receipt_comes_with_its_lines_and_what_etsy_kept(): void
    {
        $this->fakeEtsyReceipts([$this->etsyReceipt()]);

        $this->reader()->tool(EtsyReceiptGetTool::class, ['receipt_id' => 3001])
            ->assertOk()
            // Nome curto e o que o comprador escreveu, como dados.
            ->assertSee('<dados_cliente>Ana S.</dados_cliente>')
            ->assertSee('<dados_cliente>Pode embrulhar para oferta?</dados_cliente>')
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('receipt_id', 3001)
                ->where('destination.country', 'PT')
                ->where('lines.0.sku', 'VASO-P')
                ->where('lines.0.quantity', 2)
                ->where('lines.0.price.cents', 2490)
                ->where('payment.gross.cents', 5430)
                ->where('payment.fees.cents', 247)
                ->where('payment.net.cents', 5183)
                ->etc());
    }

    public function test_a_receipt_that_does_not_exist_says_so(): void
    {
        $this->fakeEtsyReceipts([]);

        $this->reader()->tool(EtsyReceiptGetTool::class, ['receipt_id' => 9999])->assertHasErrors();
    }

    // ── etsy_transactions_list ─────────────────────────────────────────────

    public function test_the_lines_can_be_asked_by_receipt_listing_or_shop(): void
    {
        $this->fakeEtsyReceipts([$this->etsyReceipt()]);
        $line = $this->etsyReceipt()['transactions'][0];
        Http::fake([
            'etsy.test/v3/application/shops/4242/listings/7100/transactions*' => Http::response(['count' => 1, 'results' => [$line]]),
            'etsy.test/v3/application/shops/4242/transactions/4001' => Http::response($line),
            'etsy.test/v3/application/shops/4242/transactions*' => Http::response(['count' => 1, 'results' => [$line]]),
        ]);

        foreach ([['receipt_id' => 3001], ['listing_id' => 7100], ['transaction_id' => 4001], []] as $arguments) {
            $this->reader()->tool(EtsyTransactionsListTool::class, $arguments)
                ->assertOk()
                ->assertStructuredContent(fn (AssertableJson $json) => $json
                    ->where('transactions.0.transaction_id', 4001)
                    ->where('transactions.0.sku', 'VASO-P')
                    ->etc());
        }

        $urls = $this->etsyRequests('/transactions')->map(fn (Request $request): string => (string) parse_url($request->url(), PHP_URL_PATH))->all();
        $this->assertSame([
            '/v3/application/shops/4242/receipts/3001/transactions',
            '/v3/application/shops/4242/listings/7100/transactions',
            '/v3/application/shops/4242/transactions/4001',
            '/v3/application/shops/4242/transactions',
        ], $urls);
    }

    // ── etsy_payments_list / etsy_ledger_list ──────────────────────────────

    public function test_the_payment_of_a_receipt(): void
    {
        $this->fakeEtsyReceipts([$this->etsyReceipt()]);

        $this->reader()->tool(EtsyPaymentsListTool::class, ['receipt_id' => 3001])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('payments.0.receipt_id', 3001)
                ->where('payments.0.gross.cents', 5430)
                ->where('payments.0.fees.cents', 247)
                ->where('payments.0.net.cents', 5183)
                ->etc());
    }

    public function test_payments_need_a_receipt_or_their_ids(): void
    {
        $this->reader()->tool(EtsyPaymentsListTool::class)->assertHasErrors();
    }

    public function test_the_ledger_always_needs_dates(): void
    {
        Http::fake(['etsy.test/v3/application/shops/4242/payment-account/ledger-entries*' => Http::response(['count' => 1, 'results' => [[
            'entry_id' => 501, 'ledger_id' => 1, 'sequence_number' => 9, 'amount' => -324, 'currency' => 'EUR',
            'description' => 'transaction', 'balance' => 10230, 'created_timestamp' => now()->timestamp,
            'ledger_type' => 'transaction', 'reference_type' => 'receipt', 'reference_id' => '3001',
        ]]])]);

        $this->reader()->tool(EtsyLedgerListTool::class)->assertHasErrors();

        $this->reader()->tool(EtsyLedgerListTool::class, ['from' => '2026-10-01', 'to' => '2026-10-13'])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('entries.0.entry_id', 501)
                ->where('entries.0.amount.cents', -324)
                ->where('entries.0.type', 'transaction')
                ->where('entries.0.reference', 'receipt 3001')
                ->etc());

        $query = $this->queryOf('/ledger-entries');
        $this->assertSame((string) Carbon::parse('2026-10-01')->startOfDay()->getTimestamp(), $query['min_created']);
        $this->assertSame((string) Carbon::parse('2026-10-13')->endOfDay()->getTimestamp(), $query['max_created']);
    }

    // ── As encomendas ja importadas ────────────────────────────────────────

    public function test_an_imported_order_shows_its_etsy_side(): void
    {
        $this->fakeEtsyReceipts([$this->etsyReceipt()]);
        app(EtsyOrderImporter::class)->run();
        $order = Order::query()->sole();

        $this->reader()->tool(OrdersListTool::class, ['sales_channel' => 'etsy'])
            ->assertOk()
            ->assertSee($order->order_number);

        $this->reader()->tool(OrderGetTool::class, ['id' => $order->id])
            ->assertOk()
            ->assertSee('<dados_cliente>Pode embrulhar para oferta?</dados_cliente>')
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('etsy.receipt_id', 3001)
                ->where('etsy.status', 'paid')
                ->where('etsy.needs_review', false)
                ->where('etsy.fees.cents', 247)
                ->etc());
    }

    public function test_an_etsy_order_cannot_be_typed_in_by_hand(): void
    {
        // Entrava duas vezes — a mao e pela importacao — e descia o stock
        // duas vezes.
        $this->writer()->tool(OrderCreateManualTool::class, [
            'customer_name' => 'Ana',
            'sales_channel' => 'etsy',
            'payment_method' => 'etsy',
            'items' => [['variant_id' => Variant::query()->where('sku', 'VASO-P')->value('id'), 'qty' => 1, 'unit_price' => '24,90']],
        ])->assertHasErrors();

        $this->assertSame(0, Order::query()->count());
    }
}
