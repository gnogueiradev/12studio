<?php

namespace Tests\Feature\PromoCodes;

use App\Jobs\IssueWelcomePromoCode;
use App\Listeners\SendWelcomePromoCode;
use App\Mail\WelcomePromoCodeMail;
use App\Models\PromoCode;
use App\Models\User;
use App\Services\PromoCodeValidator;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

class WelcomePromoCodeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();
    }

    public function test_registering_gives_one_code_and_one_email(): void
    {
        $user = User::factory()->create(['email' => 'Ana@Example.com']);

        event(new Registered($user));

        $promoCode = PromoCode::query()->sole();

        $this->assertSame(PromoCode::CHANNEL_WELCOME, $promoCode->channel);
        $this->assertSame('ana@example.com', $promoCode->email);
        $this->assertSame(PromoCode::TYPE_FIXED, $promoCode->type);
        $this->assertSame(500, $promoCode->value);
        $this->assertSame(2000, $promoCode->min_subtotal_cents);
        $this->assertSame(1, $promoCode->max_uses);
        $this->assertTrue($promoCode->single_use_per_email);
        $this->assertTrue($promoCode->expires_at->isSameDay(now()->addDays(30)));
        $this->assertNotNull($promoCode->notified_at);

        Mail::assertSent(WelcomePromoCodeMail::class, 1);
        Mail::assertSent(WelcomePromoCodeMail::class, fn (WelcomePromoCodeMail $mail): bool => $mail->hasTo('ana@example.com'));
    }

    public function test_a_second_registration_with_the_same_email_gives_nothing(): void
    {
        $user = User::factory()->create(['email' => 'ana@example.com']);
        event(new Registered($user));

        // Conta apagada e recriada com o mesmo email.
        $user->forceDelete();
        event(new Registered(User::factory()->create(['email' => 'ANA@example.com'])));

        $this->assertSame(1, PromoCode::query()->count());
        Mail::assertSent(WelcomePromoCodeMail::class, 1);
    }

    public function test_a_retry_after_a_failed_email_keeps_the_code_and_sends_once(): void
    {
        $fake = Mail::getFacadeRoot();
        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('SMTP em baixo'));

        try {
            (new IssueWelcomePromoCode(1, 'ana@example.com'))->handle();
            $this->fail('O erro do email devia subir para a fila repetir.');
        } catch (RuntimeException) {
            // Esperado.
        }

        $code = PromoCode::query()->sole()->code;
        $this->assertNull(PromoCode::query()->sole()->notified_at);

        Mail::swap($fake);

        (new IssueWelcomePromoCode(1, 'ana@example.com'))->handle();

        $this->assertSame($code, PromoCode::query()->sole()->code);
        $this->assertNotNull(PromoCode::query()->sole()->notified_at);
        Mail::assertSent(WelcomePromoCodeMail::class, 1);
    }

    public function test_the_index_refuses_a_second_welcome_code_for_the_email(): void
    {
        (new IssueWelcomePromoCode(1, 'ana@example.com'))->handle();

        $this->expectException(UniqueConstraintViolationException::class);

        PromoCode::factory()->create(['channel' => PromoCode::CHANNEL_WELCOME, 'email' => 'ana@example.com']);
    }

    public function test_the_welcome_code_can_only_be_used_by_its_email_above_the_minimum(): void
    {
        (new IssueWelcomePromoCode(1, 'ana@example.com'))->handle();
        $code = PromoCode::query()->sole()->code;
        $validator = app(PromoCodeValidator::class);

        $this->assertTrue($validator->validate($code, 2000, 'ana@example.com')->valid);
        $this->assertFalse($validator->validate($code, 1999, 'ana@example.com')->valid);
        $this->assertFalse($validator->validate($code, 2000, 'rui@example.com')->valid);
    }

    public function test_the_mail_has_the_code_and_the_conditions(): void
    {
        (new IssueWelcomePromoCode(1, 'ana@example.com'))->handle();
        $promoCode = PromoCode::query()->sole();

        $html = (new WelcomePromoCodeMail($promoCode))->render();

        $this->assertStringContainsString($promoCode->code, $html);
        $this->assertStringContainsString('5,00 €', $html);
        $this->assertStringContainsString('20,00 €', $html);
        $this->assertStringContainsString($promoCode->expires_at->format('d/m/Y'), $html);
        $this->assertStringContainsString('não acumula', $html);
    }

    public function test_the_listener_and_the_job_are_queued_with_retries(): void
    {
        $this->assertContains(ShouldQueue::class, class_implements(SendWelcomePromoCode::class));
        $this->assertContains(ShouldQueue::class, class_implements(IssueWelcomePromoCode::class));

        $job = new IssueWelcomePromoCode(1, 'ana@example.com');

        $this->assertSame(5, $job->tries);
        $this->assertSame([60, 300, 900, 3600], $job->backoff());
        $this->assertTrue(Event::hasListeners(Registered::class));
    }

    public function test_the_log_masks_the_email(): void
    {
        $log = Log::spy();
        $log->shouldReceive('channel')->with('queue')->andReturnSelf();

        (new IssueWelcomePromoCode(7, 'ana.silva@example.com'))->handle();

        $log->shouldHaveReceived('log')->withArgs(fn (string $level, string $message, array $context): bool => $context['user_id'] === 7
            && $context['email'] === 'a***@example.com'
            && $context['promo_code_id'] !== null
            && array_key_exists('job_id', $context)
            && array_key_exists('attempt', $context));
    }

    public function test_a_failed_job_logs_without_the_full_email(): void
    {
        $log = Log::spy();
        $log->shouldReceive('channel')->with('queue')->andReturnSelf();

        (new IssueWelcomePromoCode(7, 'ana@example.com'))->failed(new RuntimeException('SMTP em baixo'));

        $log->shouldHaveReceived('log')->withArgs(fn (string $level, string $message, array $context): bool => $level === 'error'
            && $context['email'] === 'a***@example.com');
    }

    public function test_a_smtp_refusal_does_not_carry_the_email_into_the_logs(): void
    {
        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('550 5.1.1 <ana@example.com>: Recipient address rejected'));
        $job = new IssueWelcomePromoCode(7, 'ana@example.com');

        try {
            $job->handle();
            $this->fail('O erro do email devia subir para a fila repetir.');
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString('ana@example.com', $exception->getMessage());
            $this->assertStringContainsString('550 5.1.1', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }

        $log = Log::spy();
        $log->shouldReceive('channel')->with('queue')->andReturnSelf();

        $job->failed(new RuntimeException('Recusado: ana@example.com'));

        $log->shouldHaveReceived('log')->withArgs(fn (string $level, string $message, array $context): bool => ! str_contains((string) $context['error'], 'ana@example.com'));
    }

    public function test_registration_stays_off(): void
    {
        $this->assertFalse(app('router')->has('register'));
    }
}
