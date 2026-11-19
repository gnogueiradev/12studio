<?php

namespace Tests\Feature\PromoCodes;

use App\Models\PromoCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PromoCodeAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function form(array $overrides = []): array
    {
        return [
            'code' => 'verao10',
            'type' => 'percent',
            'value' => '10',
            'min_subtotal' => '20,00',
            'starts_at' => null,
            'expires_at' => null,
            'max_uses' => null,
            'single_use_per_email' => true,
            'channel' => 'instagram',
            'email' => null,
            'is_active' => true,
            ...$overrides,
        ];
    }

    public function test_the_list_and_the_forms_open(): void
    {
        $promoCode = PromoCode::factory()->create(['code' => 'OLA5']);
        $this->actingAs($this->admin());

        $this->get(route('admin.codigos.index'))->assertInertia(fn (Assert $page) => $page
            ->component('admin/codigos/index', false)
            ->where('promoCodes.data.0.code', 'OLA5'));
        $this->get(route('admin.codigos.create'))->assertOk();
        $this->get(route('admin.codigos.edit', $promoCode))->assertOk();
    }

    public function test_a_code_is_stored_in_capitals_and_cents(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.codigos.store'), $this->form(['type' => 'fixed', 'value' => '5,50', 'email' => 'Ana@Example.com']))
            ->assertRedirect(route('admin.codigos.index'));

        $this->assertDatabaseHas('promo_codes', [
            'code' => 'VERAO10',
            'value' => 550,
            'min_subtotal_cents' => 2000,
            'email' => 'ana@example.com',
        ]);
    }

    public function test_the_code_must_be_unique(): void
    {
        PromoCode::factory()->create(['code' => 'VERAO10']);

        $this->actingAs($this->admin())
            ->post(route('admin.codigos.store'), $this->form())
            ->assertSessionHasErrors('code');
    }

    public function test_a_percent_must_be_an_integer_up_to_100(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('admin.codigos.store'), $this->form(['value' => '101']))->assertSessionHasErrors('value');
        $this->post(route('admin.codigos.store'), $this->form(['value' => '12.5']))->assertSessionHasErrors('value');
    }

    public function test_a_code_is_never_deleted(): void
    {
        $this->assertFalse(app('router')->has('admin.codigos.destroy'));
    }

    public function test_the_limit_cannot_go_below_what_was_used(): void
    {
        $promoCode = PromoCode::factory()->create(['code' => 'USADO', 'max_uses' => 10]);
        $promoCode->forceFill(['uses_count' => 5])->save();

        $this->actingAs($this->admin())
            ->patch(route('admin.codigos.update', $promoCode), $this->form(['code' => 'USADO', 'max_uses' => 4]))
            ->assertSessionHasErrors('max_uses');
    }

    public function test_a_welcome_code_stays_tied_to_its_email(): void
    {
        $promoCode = PromoCode::factory()->create([
            'code' => 'BEMVINDO-A',
            'channel' => PromoCode::CHANNEL_WELCOME,
            'email' => 'ana@example.com',
        ]);
        PromoCode::factory()->create([
            'code' => 'BEMVINDO-B',
            'channel' => PromoCode::CHANNEL_WELCOME,
            'email' => 'rui@example.com',
        ]);

        $this->actingAs($this->admin())
            ->patch(route('admin.codigos.update', $promoCode), $this->form([
                'code' => 'BEMVINDO-A',
                'channel' => PromoCode::CHANNEL_WELCOME,
                'email' => 'rui@example.com',
            ]))
            ->assertRedirect(route('admin.codigos.index'));

        $this->assertSame('ana@example.com', $promoCode->fresh()->email);
    }

    public function test_only_the_backoffice_gets_in(): void
    {
        $this->get(route('admin.codigos.index'))->assertRedirect();

        $this->actingAs(User::factory()->production()->create())
            ->post(route('admin.codigos.store'), $this->form())
            ->assertForbidden();

        $this->assertSame(0, PromoCode::query()->count());
    }
}
