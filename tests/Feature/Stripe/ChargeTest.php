<?php

namespace Tests\Feature\Stripe;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Variant;
use App\Stripe\Charge;
use App\Stripe\ChargeLine;
use App\Stripe\StripeCheckout;
use App\Stripe\StripeClient;
use App\Stripe\StripeException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * O que o Stripe cobra e uma descricao, nao uma encomenda: a encomenda e so
 * uma das maneiras de a escrever. As regras de sempre (somar certo, o minimo
 * de 0,50 €) valem para qualquer descricao, e quando falham nada sai daqui.
 */
class ChargeTest extends TestCase
{
    use FakesStripe;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeStripe();
    }

    /**
     * @param  list<ChargeLine>  $lines
     */
    private function charge(array $lines, int $totalCents, int $shippingCents = 0, int $couponCents = 0, ?int $expiresAt = null): Charge
    {
        return new Charge(
            reference: '17',
            metadata: ['checkout_id' => '17'],
            label: 'compra 17',
            summary: 'Compra 17: 2× Vaso.',
            currency: 'eur',
            email: 'ana@example.com',
            lines: $lines,
            shippingCents: $shippingCents,
            shippingName: 'CTT',
            extraCents: 0,
            extraName: null,
            couponCents: $couponCents,
            couponName: $couponCents > 0 ? 'OLA5' : null,
            totalCents: $totalCents,
            successUrl: 'https://12studio.test/loja/compra/17?pago=1',
            cancelUrl: 'https://12studio.test/loja/compra/17',
            expiresAt: $expiresAt,
        );
    }

    public function test_a_charge_from_an_order_carries_what_the_payload_needs(): void
    {
        $order = Order::factory()->create([
            'subtotal_cents' => 4000,
            'shipping_cents' => 400,
            'shipping_method_name' => 'CTT',
            'promo_code' => 'OLA5',
            'discount_cents' => 500,
            'adjustment_cents' => -200,
            'adjustment_reason' => 'Combinado',
            'total_cents' => 3700,
        ]);
        $variant = Variant::factory()->create();
        OrderItem::factory()->for($order)->create([
            'variant_id' => $variant->id,
            'product_name' => 'Vaso', 'variant_label' => 'G',
            'unit_price_cents' => 1500, 'qty' => 2, 'line_total_cents' => 3000,
        ]);
        OrderItem::factory()->for($order)->create([
            'product_name' => 'Taça', 'variant_label' => null,
            'unit_price_cents' => 1000, 'qty' => 1, 'line_total_cents' => 1000,
        ]);
        $returnUrl = 'https://12studio.test/pagar/abc';

        $charge = Charge::fromOrder($order->fresh(), $returnUrl);

        $this->assertSame((string) $order->id, $charge->reference);
        $this->assertSame(['order_id' => (string) $order->id, 'order_number' => $order->order_number], $charge->metadata);
        $this->assertSame("encomenda {$order->order_number}", $charge->label);
        $this->assertSame(400, $charge->shippingCents);
        $this->assertSame('CTT', $charge->shippingName);
        $this->assertSame(700, $charge->couponCents);
        $this->assertSame('OLA5', $charge->couponName);
        $this->assertSame(0, $charge->extraCents);
        $this->assertSame(3700, $charge->totalCents);
        $this->assertSame($returnUrl.'?pago=1', $charge->successUrl);
        $this->assertSame($returnUrl, $charge->cancelUrl);
        $this->assertNull($charge->expiresAt);

        $this->assertCount(2, $charge->lines);
        $this->assertSame('Vaso (G)', $charge->lines[0]->name);
        $this->assertSame(2, $charge->lines[0]->qty);
        $this->assertSame(3000, $charge->lines[0]->lineTotalCents);
        $this->assertTrue($variant->is($charge->lines[0]->variant));
        $this->assertTrue($charge->lines[0]->variant->relationLoaded('product'));
        $this->assertSame('Taça', $charge->lines[1]->name);
        $this->assertSame("Encomenda {$order->order_number}: 2× Vaso (G); 1× Taça.", $charge->summary);
    }

    public function test_a_charge_that_does_not_add_up_is_never_sent(): void
    {
        $charge = $this->charge(
            [new ChargeLine('Vaso', 2, 3000)],
            totalCents: 9999,
            shippingCents: 450,
            couponCents: 500,
        );

        try {
            app(StripeCheckout::class)->sessionPayload($charge);
            $this->fail('Devia ter recusado.');
        } catch (StripeException $exception) {
            $this->assertSame('As linhas da compra 17 somam 2950 cêntimos e o total é 9999. Nada foi cobrado.', $exception->getMessage());
        }

        $this->assertSame([], $this->stripeCalls);
    }

    public function test_below_the_stripe_minimum_nothing_is_sent(): void
    {
        $charge = $this->charge([new ChargeLine('Porta-chaves', 1, 49)], totalCents: 49);

        try {
            app(StripeCheckout::class)->sessionPayload($charge);
            $this->fail('Devia ter recusado.');
        } catch (StripeException $exception) {
            $this->assertSame('O total da compra 17 fica abaixo de 0,50 €, o mínimo do Stripe. Nada foi cobrado.', $exception->getMessage());
        }

        $this->assertSame([], $this->stripeCalls);
    }

    /**
     * O sessionPayload soma `extra - cupao` sempre, mas so os manda quando
     * sao positivos: um valor negativo passava na conferencia e cobrava outra
     * coisa. A descricao nem chega a existir.
     *
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function negativeCharges(): array
    {
        return [
            'portes negativos' => [['shippingCents' => -450]],
            'extra negativo' => [['extraCents' => -500]],
            'cupao negativo' => [['couponCents' => -500]],
            'total negativo' => [['totalCents' => -3000]],
            'linha sem quantidade' => [['lines' => [new ChargeLine('Vaso', 0, 3000)]]],
            'linha negativa' => [['lines' => [new ChargeLine('Vaso', 1, -3000)]]],
        ];
    }

    /**
     * @param  array<string, mixed>  $override
     */
    #[DataProvider('negativeCharges')]
    public function test_a_charge_with_a_negative_amount_cannot_be_written(array $override): void
    {
        $arguments = [
            'reference' => '17',
            'metadata' => ['checkout_id' => '17'],
            'label' => 'compra 17',
            'summary' => 'Compra 17: 2× Vaso.',
            'currency' => 'eur',
            'email' => null,
            'lines' => [new ChargeLine('Vaso', 2, 3000)],
            'shippingCents' => 0,
            'shippingName' => null,
            'extraCents' => 0,
            'extraName' => null,
            'couponCents' => 0,
            'couponName' => null,
            'totalCents' => 3000,
            'successUrl' => 'https://12studio.test/compra/17',
            'cancelUrl' => 'https://12studio.test/finalizar',
            ...$override,
        ];

        $this->expectException(StripeException::class);

        new Charge(...$arguments);
    }

    public function test_expires_at_reaches_the_session(): void
    {
        $expiresAt = now()->getTimestamp() + 3600;
        $charge = $this->charge([new ChargeLine('Vaso', 2, 3000)], totalCents: 3000, expiresAt: $expiresAt);

        $payload = app(StripeCheckout::class)->sessionPayload($charge);
        $session = app(StripeClient::class)->post('checkout/sessions', $payload);

        $this->assertSame((string) $expiresAt, $this->stripe['sessions'][$session['id']]['request']['expires_at']);
        $this->assertSame($expiresAt, $session['expires_at']);
        $this->assertSame(3000, $session['amount_total']);
    }
}
