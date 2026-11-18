<?php

namespace Tests\Feature\PromoCodes;

use App\Models\Order;
use App\Models\PromoCode;
use App\Models\PromoCodeRedemption;
use App\Services\PromoCodeValidator;
use App\Support\PromoCodeValidation as Validation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class PromoCodeValidatorTest extends TestCase
{
    use RefreshDatabase;

    private function validate(string $code, int $subtotal = 3000, ?string $email = 'ana@example.com'): Validation
    {
        return app(PromoCodeValidator::class)->validate($code, $subtotal, $email);
    }

    public function test_a_valid_code_returns_the_discount(): void
    {
        PromoCode::factory()->create(['code' => 'OLA5', 'value' => 500]);

        $result = $this->validate('  ola5 ');

        $this->assertTrue($result->valid);
        $this->assertSame(500, $result->discountCents);
        $this->assertNull($result->reason);
    }

    public function test_an_unknown_code_is_refused(): void
    {
        $this->assertSame(Validation::NOT_FOUND, $this->validate('NADA')->reason);
    }

    public function test_an_expired_code_is_refused(): void
    {
        PromoCode::factory()->create(['code' => 'VELHO', 'expires_at' => now()->subMinute()]);

        $this->assertSame(Validation::EXPIRED, $this->validate('VELHO')->reason);
    }

    public function test_a_code_that_has_not_started_is_refused(): void
    {
        PromoCode::factory()->create(['code' => 'CEDO', 'starts_at' => now()->addDay()]);

        $this->assertSame(Validation::NOT_STARTED, $this->validate('CEDO')->reason);
    }

    public function test_an_inactive_code_is_refused(): void
    {
        PromoCode::factory()->create(['code' => 'DESLIGADO', 'is_active' => false]);

        $this->assertSame(Validation::INACTIVE, $this->validate('DESLIGADO')->reason);
    }

    public function test_a_subtotal_below_the_minimum_is_refused(): void
    {
        PromoCode::factory()->create(['code' => 'MIN20', 'min_subtotal_cents' => 2000]);

        $this->assertSame(Validation::BELOW_MINIMUM, $this->validate('MIN20', 1999)->reason);
        $this->assertTrue($this->validate('MIN20', 2000)->valid);
    }

    public function test_an_exhausted_code_is_refused(): void
    {
        $promoCode = PromoCode::factory()->create(['code' => 'ACABOU', 'max_uses' => 2]);
        $promoCode->forceFill(['uses_count' => 2])->save();

        $this->assertSame(Validation::EXHAUSTED, $this->validate('ACABOU')->reason);
    }

    public function test_a_code_already_used_by_the_email_is_refused(): void
    {
        $promoCode = PromoCode::factory()->create(['code' => 'UMAVEZ', 'single_use_per_email' => true]);
        PromoCodeRedemption::query()->create([
            'promo_code_id' => $promoCode->id,
            'order_id' => Order::factory()->create()->id,
            'email' => 'ana@example.com',
            'unique_email' => 'ana@example.com',
            'discount_cents' => 500,
        ]);

        $this->assertSame(Validation::ALREADY_USED, $this->validate('UMAVEZ', email: 'ANA@example.com')->reason);
        $this->assertTrue($this->validate('UMAVEZ', email: 'rui@example.com')->valid);
    }

    public function test_a_personal_code_only_works_for_its_email(): void
    {
        PromoCode::factory()->create(['code' => 'PESSOAL', 'email' => 'ana@example.com']);

        $this->assertSame(Validation::WRONG_EMAIL, $this->validate('PESSOAL', email: 'rui@example.com')->reason);
        $this->assertSame(Validation::EMAIL_REQUIRED, $this->validate('PESSOAL', email: null)->reason);
        $this->assertTrue($this->validate('PESSOAL', email: 'Ana@Example.com')->valid);
    }

    public function test_a_fixed_discount_is_never_more_than_the_subtotal(): void
    {
        PromoCode::factory()->create(['code' => 'GRANDE', 'value' => 5000]);

        $this->assertSame(1200, $this->validate('GRANDE', 1200)->discountCents);
    }

    public function test_a_percent_discount_rounds_to_the_cent(): void
    {
        PromoCode::factory()->percent(15)->create(['code' => 'QUINZE']);

        // 15 % de 9,99 € = 1,4985 €; 15 % de 10,10 € = 1,515 €.
        $this->assertSame(150, $this->validate('QUINZE', 999)->discountCents);
        $this->assertSame(152, $this->validate('QUINZE', 1010)->discountCents);
    }

    public function test_the_log_says_the_result_and_the_reason(): void
    {
        $log = Log::spy();
        $log->shouldReceive('channel')->with('api')->andReturnSelf();

        app(PromoCodeValidator::class)->validate('NADA', 100, 'ana@example.com', ['order_id' => 7]);

        $log->shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $context['code'] === 'NADA'
            && $context['order_id'] === 7
            && $context['result'] === 'refused'
            && $context['reason'] === Validation::NOT_FOUND
            && ! in_array('ana@example.com', $context, true));
    }
}
