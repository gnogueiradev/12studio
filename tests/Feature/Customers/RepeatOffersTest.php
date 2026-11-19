<?php

namespace Tests\Feature\Customers;

use App\Customers\RepeatOffers;
use App\Mail\RepeatOfferMail;
use App\Mail\RepeatOfferReminderMail;
use App\Models\Checkout;
use App\Models\Order;
use App\Models\PromoCode;
use App\Models\PromoCodeRedemption;
use App\Models\RepeatOffer;
use App\Models\User;
use App\Services\SettingService;
use App\Services\StoreSettings;
use Closure;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Stripe\FakesStripe;
use Tests\TestCase;

/**
 * As ofertas para voltar a comprar: um codigo pessoal de 10 % uns dias depois
 * do envio, e um lembrete antes de expirar. So para compras feitas no checkout
 * com a caixa de recusar por marcar (a Lei 41/2004), nunca a quem recusou, a
 * equipa ou a uma encomenda que ja teve a sua.
 */
class RepeatOffersTest extends TestCase
{
    use FakesStripe;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setDate(2026, 11, 19)->setTime(10, 0));
        Mail::fake();
        $this->fakeStripe();
        app(SettingService::class)->set(StoreSettings::KEY_SHOP, 'on');
        app(SettingService::class)->set(StoreSettings::KEY_OFFERS, ['enabled' => true]);
    }

    /**
     * Uma encomenda paga e enviada de um cliente com conta, nascida de um
     * checkout com a caixa por marcar: tudo o que da direito a oferta.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function shippedOrder(
        array $attributes = [],
        int $daysAgo = 21,
        ?bool $optOut = false,
        bool $withCheckout = true,
        ?User $user = null,
    ): Order {
        $user ??= User::factory()->create();

        $order = Order::factory()->paid()->channel('website')->create([
            'user_id' => $user->id,
            'email' => $user->email,
            'status' => 'shipped',
            'shipped_at' => now()->subDays($daysAgo),
            'created_at' => now()->subDays($daysAgo + 3),
            ...$attributes,
        ]);

        if ($withCheckout) {
            Checkout::factory()->create([
                'user_id' => $user->id,
                'order_id' => $order->id,
                'status' => 'completed',
                'marketing_opt_out' => $optOut,
            ]);
        }

        return $order;
    }

    public function test_nothing_is_sent_while_disabled_or_the_shop_is_not_on(): void
    {
        $this->shippedOrder();

        app(SettingService::class)->set(StoreSettings::KEY_OFFERS, ['enabled' => false]);
        $this->artisan('offers:send')->assertSuccessful();

        app(SettingService::class)->set(StoreSettings::KEY_OFFERS, ['enabled' => true]);
        foreach (['off', 'staff'] as $mode) {
            app(SettingService::class)->set(StoreSettings::KEY_SHOP, $mode);
            $this->artisan('offers:send')->assertSuccessful();
        }

        $this->assertSame(0, RepeatOffer::query()->count());
        $this->assertSame(0, PromoCode::query()->where('channel', PromoCode::CHANNEL_REPEAT)->count());
        Mail::assertNothingQueued();

        // A mesma encomenda, com tudo ligado, ja recebe.
        app(SettingService::class)->set(StoreSettings::KEY_SHOP, 'on');
        $this->artisan('offers:send')->assertSuccessful();

        $this->assertSame(1, RepeatOffer::query()->count());
    }

    public function test_an_order_shipped_21_days_ago_gets_a_personal_ten_percent_code(): void
    {
        $order = $this->shippedOrder(['email' => 'Ana@Exemplo.pt ']);
        $tooSoon = $this->shippedOrder(daysAgo: 20);

        $this->artisan('offers:send')->assertSuccessful();

        $promoCode = PromoCode::query()->where('channel', PromoCode::CHANNEL_REPEAT)->sole();
        $this->assertSame('repeat', $promoCode->channel);
        $this->assertSame('percent', $promoCode->type);
        $this->assertSame(10, $promoCode->value);
        $this->assertSame('ana@exemplo.pt', $promoCode->email);
        $this->assertSame(0, $promoCode->min_subtotal_cents);
        $this->assertSame(1, $promoCode->max_uses);
        $this->assertTrue($promoCode->single_use_per_email);
        $this->assertTrue($promoCode->is_active);
        $this->assertMatchesRegularExpression('/^VOLTA-[A-Z0-9]{8}$/', $promoCode->code);
        $this->assertSame(now()->addDays(45)->toDateTimeString(), $promoCode->expires_at?->toDateTimeString());

        $offer = RepeatOffer::query()->sole();
        $this->assertSame($order->id, $offer->order_id);
        $this->assertSame($promoCode->id, $offer->promo_code_id);
        $this->assertSame('ana@exemplo.pt', $offer->email);
        $this->assertSame(now()->toDateTimeString(), $offer->sent_at->toDateTimeString());
        $this->assertNull($offer->reminded_at);
        $this->assertFalse(RepeatOffer::query()->where('order_id', $tooSoon->id)->exists());

        Mail::assertQueued(RepeatOfferMail::class, fn (RepeatOfferMail $mail): bool => $mail->hasTo('ana@exemplo.pt')
            && $mail->promoCode->is($promoCode)
            && $mail->orderNumber === $order->order_number);
        Mail::assertQueuedCount(1);
    }

    public function test_the_offer_email_carries_the_one_click_unsubscribe(): void
    {
        $order = $this->shippedOrder();

        $this->artisan('offers:send')->assertSuccessful();

        $mail = Mail::queued(RepeatOfferMail::class)->sole();
        $unsubscribe = (string) $mail->headers()->text['List-Unsubscribe'];
        $this->assertSame('List-Unsubscribe=One-Click', $mail->headers()->text['List-Unsubscribe-Post']);
        $this->assertMatchesRegularExpression('#^<https?://[^>]+/ofertas/sair/'.$order->user_id.'\?signature=[a-f0-9]+>$#', $unsubscribe);

        $html = $mail->render();
        $this->assertStringContainsString($order->order_number, $html);
        $this->assertStringContainsString('/ofertas/sair/'.$order->user_id.'?signature=', $html);
        // Da encomenda, so o numero: nem morada, nem nome.
        $this->assertStringNotContainsString($order->customer_name, $html);
    }

    public function test_each_order_gets_one_offer_even_if_the_command_runs_twice(): void
    {
        $this->shippedOrder();

        $this->artisan('offers:send')->assertSuccessful();
        $this->artisan('offers:send')->assertSuccessful();

        $this->assertSame(1, RepeatOffer::query()->count());
        $this->assertSame(1, PromoCode::query()->where('channel', PromoCode::CHANNEL_REPEAT)->count());
        Mail::assertQueuedCount(1);
    }

    /**
     * @return array<string, array{0: Closure(self): void}>
     */
    public static function reasonsForNoOffer(): array
    {
        return [
            'a checkout with the opt-out box ticked' => [fn (self $test) => $test->shippedOrder(optOut: true)],
            'a checkout from before the box existed' => [fn (self $test) => $test->shippedOrder(optOut: null)],
            'a backoffice order without checkout' => [fn (self $test) => $test->shippedOrder(['sales_channel' => 'manual'], withCheckout: false)],
            'a customer who refused offers' => [function (self $test): void {
                $user = User::factory()->create();
                $user->forceFill(['marketing_opt_out_at' => now()])->save();
                $test->shippedOrder(user: $user);
            }],
            'a staff account' => [fn (self $test) => $test->shippedOrder(user: User::factory()->production()->create())],
            'a newer paid order from the same email' => [function (self $test): void {
                $order = $test->shippedOrder();
                Order::factory()->paid()->create([
                    'email' => strtoupper((string) $order->email),
                    'created_at' => now()->subDay(),
                ]);
            }],
            'an active unused repeat code for the email' => [function (self $test): void {
                $order = $test->shippedOrder();
                PromoCode::factory()->percent(10)->create([
                    'channel' => PromoCode::CHANNEL_REPEAT,
                    'email' => $order->email,
                    'expires_at' => now()->addDays(10),
                ]);
            }],
            'a cancelled order' => [fn (self $test) => $test->shippedOrder(['status' => 'cancelled'])],
            'a refunded order' => [fn (self $test) => $test->shippedOrder(['status' => 'refunded'])],
            'shipped 60 days ago, outside the window' => [fn (self $test) => $test->shippedOrder(daysAgo: 60)],
        ];
    }

    /**
     * @param  Closure(self): void  $setUp
     */
    #[DataProvider('reasonsForNoOffer')]
    public function test_no_offer_for(Closure $setUp): void
    {
        $setUp($this);

        $this->artisan('offers:send')->assertSuccessful();

        $this->assertSame(0, RepeatOffer::query()->count());
        Mail::assertNothingQueued();
    }

    public function test_the_reminder_goes_seven_days_before_expiry_and_only_if_unused(): void
    {
        $kept = $this->shippedOrder();
        $used = $this->shippedOrder();
        $refused = $this->shippedOrder();
        $this->artisan('offers:send')->assertSuccessful();
        Mail::assertQueued(RepeatOfferMail::class, 3);

        // Usado numa compra: ja nao ha nada a lembrar.
        $usedCode = RepeatOffer::query()->where('order_id', $used->id)->sole()->promoCode;
        PromoCodeRedemption::query()->create([
            'promo_code_id' => $usedCode->id,
            'order_id' => Order::factory()->paid()->create()->id,
            'email' => $usedCode->email,
            'unique_email' => $usedCode->email,
            'discount_cents' => 150,
        ]);
        // Recusou entretanto, pelo link do email ou pela conta.
        $refused->user?->forceFill(['marketing_opt_out_at' => now()])->save();

        // A oito dias de expirar, ainda nao.
        $this->travel(37)->days();
        $this->artisan('offers:send')->assertSuccessful();
        Mail::assertNotQueued(RepeatOfferReminderMail::class);

        // A sete, sim — so para o codigo por usar de quem nao recusou.
        $this->travel(1)->days();
        $this->travel(1)->hours();
        $this->artisan('offers:send')->assertSuccessful();
        $this->artisan('offers:send')->assertSuccessful();

        Mail::assertQueued(RepeatOfferReminderMail::class, 1);
        Mail::assertQueued(RepeatOfferReminderMail::class, fn (RepeatOfferReminderMail $mail): bool => $mail->hasTo($kept->email)
            && $mail->orderNumber === $kept->order_number);
        $this->assertNotNull(RepeatOffer::query()->where('order_id', $kept->id)->sole()->reminded_at);
        $this->assertNull(RepeatOffer::query()->where('order_id', $used->id)->sole()->reminded_at);
        $this->assertNull(RepeatOffer::query()->where('order_id', $refused->id)->sole()->reminded_at);

        $reminder = Mail::queued(RepeatOfferReminderMail::class)->sole();
        $this->assertSame('List-Unsubscribe=One-Click', $reminder->headers()->text['List-Unsubscribe-Post']);
        $this->assertStringContainsString('/ofertas/sair/'.$kept->user_id.'?signature=', $reminder->render());

        // Expirado, nunca.
        $this->travel(8)->days();
        $this->artisan('offers:send')->assertSuccessful();
        Mail::assertQueued(RepeatOfferReminderMail::class, 1);
    }

    /**
     * O que muda entre a oferta e o lembrete e que tira o direito a ele.
     *
     * @return array<string, array{0: Closure(Order, RepeatOffer): void}>
     */
    public static function reasonsForNoReminder(): array
    {
        return [
            'the code was switched off' => [fn (Order $order, RepeatOffer $offer) => $offer->promoCode->update(['is_active' => false])],
            'the order was cancelled' => [fn (Order $order) => $order->update(['status' => 'cancelled'])],
            'the order was refunded' => [fn (Order $order) => $order->update(['status' => 'refunded', 'payment_status' => 'refunded'])],
            'the customer joined the staff' => [fn (Order $order) => $order->user?->forceFill(['staff_role' => User::ROLE_PRODUCTION])->save()],
            'the customer refused offers' => [fn (Order $order) => $order->user?->forceFill(['marketing_opt_out_at' => now()])->save()],
            'a newer paid order from the same email' => [fn (Order $order) => Order::factory()->paid()->create([
                'email' => strtoupper((string) $order->email),
            ])],
        ];
    }

    /**
     * @param  Closure(Order, RepeatOffer): void  $change
     */
    #[DataProvider('reasonsForNoReminder')]
    public function test_no_reminder_when(Closure $change): void
    {
        $order = $this->shippedOrder();
        $this->artisan('offers:send')->assertSuccessful();
        $offer = RepeatOffer::query()->sole();

        $change($order->fresh() ?? $order, $offer);

        $this->travel(38)->days();
        $this->travel(1)->hours();
        $this->artisan('offers:send')->assertSuccessful();

        Mail::assertNotQueued(RepeatOfferReminderMail::class);
        $this->assertNull($offer->fresh()?->reminded_at);
    }

    public function test_two_runs_with_the_same_offer_remind_once(): void
    {
        $this->shippedOrder();
        $this->artisan('offers:send')->assertSuccessful();

        // Duas corridas que leram a mesma oferta antes de qualquer delas a marcar.
        $first = RepeatOffer::query()->sole();
        $second = RepeatOffer::query()->sole();
        app(RepeatOffers::class)->remind($first);
        app(RepeatOffers::class)->remind($second);

        Mail::assertQueued(RepeatOfferReminderMail::class, 1);
    }

    public function test_one_failing_offer_does_not_stop_the_others(): void
    {
        Exceptions::fake();
        $poisoned = $this->shippedOrder();
        $fine = $this->shippedOrder();

        $this->app->instance(RepeatOffers::class, new class(app(StoreSettings::class), $poisoned->id) extends RepeatOffers
        {
            public function __construct(StoreSettings $settings, private int $poisonedId)
            {
                parent::__construct($settings);
            }

            public function send(Order $order): void
            {
                if ($order->id === $this->poisonedId) {
                    throw new RuntimeException("O SMTP recusou {$order->email}");
                }

                parent::send($order);
            }
        });

        $this->artisan('offers:send')->assertSuccessful();

        $this->assertTrue(RepeatOffer::query()->where('order_id', $fine->id)->exists());
        $this->assertFalse(RepeatOffer::query()->where('order_id', $poisoned->id)->exists());
        Mail::assertQueued(RepeatOfferMail::class, 1);
        Exceptions::assertReported(fn (RuntimeException $exception): bool => str_contains($exception->getMessage(), "#{$poisoned->id}")
            && ! str_contains($exception->getMessage(), (string) $poisoned->email)
            && $exception->getPrevious() === null);
    }

    public function test_offers_go_out_only_during_the_day(): void
    {
        $this->assertFalse($this->offersScheduledAt(22)->filtersPass($this->app));
        $this->assertFalse($this->offersScheduledAt(7)->filtersPass($this->app));
        $this->assertTrue($this->offersScheduledAt(10)->filtersPass($this->app));
    }

    /**
     * O agendamento das ofertas, como o `schedule:run` o monta a essa hora:
     * o `between` guarda a hora em que o agendamento se declara.
     */
    private function offersScheduledAt(int $hour): Event
    {
        $this->travelTo(now()->setTime($hour, 0));
        $schedule = new Schedule;
        ScheduleFacade::swap($schedule);

        require base_path('routes/console.php');

        $event = collect($schedule->events())
            ->first(fn (Event $event): bool => str_contains((string) $event->command, 'offers:send'));
        $this->assertInstanceOf(Event::class, $event);

        return $event;
    }

    public function test_the_offer_settings_are_saved_and_change_the_offer(): void
    {
        $admin = User::factory()->admin()->create();
        $form = [
            'open' => true,
            'shop' => 'on',
            'offers' => [
                'enabled' => true,
                'percent' => 15,
                'days_after_shipping' => 14,
                'valid_days' => 30,
                'reminder_days' => 5,
            ],
        ];

        $this->actingAs($admin)
            ->patch(route('admin.montra.update'), $form)
            ->assertSessionHasNoErrors();

        $this->assertSame($form['offers'], app(StoreSettings::class)->offers());
        $this->assertSame($form['offers'], app(StoreSettings::class)->toForm()['offers']);

        // O lembrete tem de caber na validade, e a percentagem tem teto.
        $this->actingAs($admin)
            ->patch(route('admin.montra.update'), array_replace_recursive($form, ['offers' => ['percent' => 51, 'reminder_days' => 30]]))
            ->assertSessionHasErrors(['offers.percent', 'offers.reminder_days']);

        $this->shippedOrder(daysAgo: 14);
        $this->artisan('offers:send')->assertSuccessful();

        $promoCode = PromoCode::query()->where('channel', PromoCode::CHANNEL_REPEAT)->sole();
        $this->assertSame(15, $promoCode->value);
        $this->assertSame(now()->addDays(30)->toDateTimeString(), $promoCode->expires_at?->toDateTimeString());
    }

    public function test_without_saved_settings_the_offers_are_off_with_the_defaults(): void
    {
        app(SettingService::class)->forget(StoreSettings::KEY_OFFERS);

        $this->assertSame([
            'enabled' => false,
            'percent' => 10,
            'days_after_shipping' => 21,
            'valid_days' => 45,
            'reminder_days' => 7,
        ], app(StoreSettings::class)->offers());
    }
}
