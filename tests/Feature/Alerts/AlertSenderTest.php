<?php

namespace Tests\Feature\Alerts;

use App\Alerts\AlertChannel;
use App\Alerts\AlertSender;
use App\Alerts\DiscordMessage;
use App\Jobs\SendDiscordAlert;
use App\Models\AlertState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class AlertSenderTest extends TestCase
{
    use FakesDiscord;
    use RefreshDatabase;

    public function test_a_message_reaches_its_channel(): void
    {
        $this->fakeDiscord();

        app(AlertSender::class)->send(AlertChannel::SYSTEM, DiscordMessage::make('Olá', DiscordMessage::SUCCESS)
            ->field('Campo', 'valor'));

        $embed = $this->alertEmbed(AlertChannel::SYSTEM, 'Olá');
        $this->assertSame('Campo: valor', $embed['fields'][0]['name'].': '.$embed['fields'][0]['value']);
        $this->assertSame([], $this->alertTitles(AlertChannel::ORDERS));
    }

    public function test_an_empty_webhook_sends_nothing_and_breaks_nothing(): void
    {
        Http::fake();

        app(AlertSender::class)->send(AlertChannel::ORDERS, DiscordMessage::make('Nada'));

        Http::assertNothingSent();
    }

    public function test_nobody_gets_mentioned_by_accident(): void
    {
        $this->fakeDiscord();

        app(AlertSender::class)->send(AlertChannel::ORDERS, DiscordMessage::make('@everyone compra'));

        Http::assertSent(fn ($request): bool => $request->data()['allowed_mentions'] === ['parse' => []]);
    }

    public function test_a_rolled_back_transaction_sends_nothing(): void
    {
        $this->fakeDiscord();

        try {
            DB::transaction(function (): void {
                app(AlertSender::class)->send(AlertChannel::ORDERS, DiscordMessage::make('Fantasma'));

                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame([], $this->alertTitles(AlertChannel::ORDERS));
    }

    public function test_discords_limits_are_respected(): void
    {
        $payload = DiscordMessage::make(str_repeat('t', 400))
            ->description(str_repeat('d', 5000))
            ->field('Morada', str_repeat('m', 2000))
            ->toPayload();

        $embed = $payload['embeds'][0];
        $this->assertLessThanOrEqual(256, Str::length($embed['title']));
        $this->assertLessThanOrEqual(4096, Str::length($embed['description']));
        $this->assertLessThanOrEqual(1024, Str::length($embed['fields'][0]['value']));
    }

    public function test_empty_fields_are_left_out(): void
    {
        $payload = DiscordMessage::make('X')->field('Telefone', null)->field('Email', '')->toPayload();

        $this->assertArrayNotHasKey('fields', $payload['embeds'][0]);
    }

    public function test_a_rate_limited_alert_waits_what_discord_asks(): void
    {
        $this->fakeDiscord(Http::response(['retry_after' => 2.4], 429));

        $job = (new SendDiscordAlert(AlertChannel::SYSTEM, ['content' => 'x']))->withFakeQueueInteractions();
        $job->handle();

        $job->assertReleased(delay: 3);
    }

    public function test_a_discord_outage_is_retried(): void
    {
        $this->fakeDiscord(Http::response('', 502));

        $job = (new SendDiscordAlert(AlertChannel::SYSTEM, ['content' => 'x']))->withFakeQueueInteractions();
        $job->handle();

        $job->assertReleased(delay: 30);
    }

    public function test_send_now_never_throws(): void
    {
        config(['alerts.webhooks.sistema' => 'https://discord.test/sistema']);
        Http::fake(fn () => throw new RuntimeException('sem rede'));

        $this->assertFalse(app(AlertSender::class)->sendNow(AlertChannel::SYSTEM, DiscordMessage::make('X')));
    }

    public function test_the_test_command_reaches_every_configured_channel(): void
    {
        $this->fakeDiscord();
        config(['alerts.webhooks.stock' => null]);

        $this->artisan('alerts:test')->assertSuccessful();

        foreach ([AlertChannel::ORDERS, AlertChannel::SECURITY, AlertChannel::SYSTEM] as $channel) {
            $this->assertSame(['🔔 Teste de ligação'], $this->alertTitles($channel));
        }
        $this->assertSame([], $this->alertTitles(AlertChannel::STOCK));
    }

    public function test_the_test_command_fails_when_discord_refuses(): void
    {
        $this->fakeDiscord(Http::response('', 404));

        $this->artisan('alerts:test')->assertFailed();
    }

    public function test_claim_warns_once_then_again_after_the_reminder_interval(): void
    {
        $this->assertTrue(AlertState::claim('teste:1'));
        $this->assertFalse(AlertState::claim('teste:1'));

        $this->travel(25)->hours();
        $this->assertTrue(AlertState::claim('teste:1'));

        AlertState::release('teste:1');
        $this->assertTrue(AlertState::claim('teste:1'));
    }
}
