<?php

namespace Tests\Feature\Admin;

use App\Alerts\AlertChannel;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Alerts\FakesDiscord;
use Tests\TestCase;

class AlertSettingsPageTest extends TestCase
{
    use FakesDiscord;
    use RefreshDatabase;

    private const URL = 'https://discord.com/api/webhooks/123456789/Tok3n-secreto_do-canalAB12';

    private function confirmed(User $user): static
    {
        return $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
    }

    public function test_the_page_asks_for_the_password_again(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.alertas.index'))
            ->assertRedirect(route('password.confirm'));
    }

    public function test_customers_and_production_cannot_open_it(): void
    {
        $this->confirmed(User::factory()->create())->get(route('admin.alertas.index'))->assertForbidden();
        $this->confirmed(User::factory()->production()->create())->get(route('admin.alertas.index'))->assertForbidden();
    }

    public function test_an_admin_saves_a_webhook_and_alerts_start_using_it(): void
    {
        Http::fake();

        $this->confirmed(User::factory()->admin()->create())
            ->put(route('admin.alertas.update', AlertChannel::ORDERS), ['url' => '  '.self::URL."\n"])
            ->assertSessionHasNoErrors();

        $this->assertSame(self::URL, AlertChannel::webhook(AlertChannel::ORDERS));
    }

    public function test_the_webhook_is_stored_encrypted(): void
    {
        Http::fake();

        $this->confirmed(User::factory()->admin()->create())
            ->put(route('admin.alertas.update', AlertChannel::ORDERS), ['url' => self::URL]);

        $raw = (string) Setting::query()->whereKey('alerts.webhook.encomendas')->value('value');
        $this->assertNotSame('', $raw);
        $this->assertStringNotContainsString('Tok3n-secreto', $raw);
    }

    public function test_only_discord_webhooks_are_accepted(): void
    {
        $admin = User::factory()->admin()->create();

        foreach ([
            'https://evil.example/api/webhooks/1/abc',
            'https://discord.com.evil.example/api/webhooks/1/abc',
            'http://discord.com/api/webhooks/1/abc',
            'https://discord.com/channels/1/2',
        ] as $url) {
            $this->confirmed($admin)
                ->put(route('admin.alertas.update', AlertChannel::ORDERS), ['url' => $url])
                ->assertSessionHasErrors('url');
        }

        $this->assertNull(AlertChannel::webhook(AlertChannel::ORDERS));
    }

    public function test_the_page_never_shows_the_full_url(): void
    {
        Http::fake();
        $admin = User::factory()->admin()->create();
        $this->confirmed($admin)->put(route('admin.alertas.update', AlertChannel::SYSTEM), ['url' => self::URL]);

        $this->confirmed($admin)
            ->get(route('admin.alertas.index'))
            ->assertDontSee('Tok3n-secreto', false)
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/definicoes/alertas')
                ->has('channels', 4)
                ->where('channels.3.key', 'sistema')
                ->where('channels.3.source', 'backoffice')
                ->where('channels.3.hint', '…AB12')
                ->where('channels.0.source', 'off'));
    }

    public function test_the_backoffice_wins_over_the_env_and_removing_falls_back(): void
    {
        Http::fake();
        config(['alerts.webhooks.stock' => 'https://discord.com/api/webhooks/1/do-env']);
        $admin = User::factory()->admin()->create();

        $this->confirmed($admin)->put(route('admin.alertas.update', AlertChannel::STOCK), ['url' => self::URL]);
        $this->assertSame(self::URL, AlertChannel::webhook(AlertChannel::STOCK));

        $this->confirmed($admin)->delete(route('admin.alertas.destroy', AlertChannel::STOCK));
        $this->assertSame('https://discord.com/api/webhooks/1/do-env', AlertChannel::webhook(AlertChannel::STOCK));
    }

    public function test_the_test_button_sends_right_away(): void
    {
        $this->fakeDiscord();

        $this->confirmed(User::factory()->admin()->create(['name' => 'Rita']))
            ->post(route('admin.alertas.test', AlertChannel::SYSTEM))
            ->assertRedirect();

        $this->assertStringContainsString(
            'Enviado por Rita',
            $this->embedText($this->alertEmbed(AlertChannel::SYSTEM, 'Teste de ligação')),
        );
    }

    public function test_changing_the_security_webhook_warns_the_old_one_too(): void
    {
        $this->fakeDiscord();
        $admin = User::factory()->admin()->create(['name' => 'Rita']);

        $this->confirmed($admin)->put(route('admin.alertas.update', AlertChannel::SECURITY), [
            'url' => 'https://discord.com/api/webhooks/999/desvio',
        ]);

        // O antigo (o do teste, via config) recebe o aviso...
        $this->assertAlerted(AlertChannel::SECURITY, 'Webhook de #seguranca trocado');

        // ...e o novo tambem.
        Http::assertSent(fn ($request): bool => $request->url() === 'https://discord.com/api/webhooks/999/desvio'
            && str_contains((string) data_get($request->data(), 'embeds.0.title'), 'Webhook de #seguranca trocado'));
    }

    public function test_unknown_channels_are_404(): void
    {
        $this->confirmed(User::factory()->admin()->create())
            ->put('/admin/definicoes/alertas/qualquer/guardar', ['url' => self::URL])
            ->assertNotFound();
    }
}
