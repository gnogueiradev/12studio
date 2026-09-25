<?php

namespace Tests\Feature\Admin;

use App\Mail\StaffAccountChangedMail;
use App\Models\User;
use App\Support\ManualOrderOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StaffManagementTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Uma-password-bem-longa-42';

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->owner = User::factory()->owner()->create(['email' => 'dono@12studio.test']);
    }

    /**
     * Dono autenticado e com a password confirmada ha instantes.
     */
    private function asOwner(): static
    {
        return $this->actingAs($this->owner)
            ->withSession(['auth.password_confirmed_at' => time()]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'Rita Produção',
            'email' => 'Rita@Example.test',
            'role' => 'production',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            ...$overrides,
        ];
    }

    public function test_only_the_owner_sees_the_staff_page(): void
    {
        $this->asOwner()
            ->get(route('admin.utilizadores.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/utilizadores/index')
                ->has('staff', 1)
                ->where('staff.0.role', 'owner'));

        $this->actingAs(User::factory()->admin()->create())
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('admin.utilizadores.index'))
            ->assertForbidden();
    }

    public function test_every_action_asks_for_the_password_again(): void
    {
        $this->actingAs($this->owner)
            ->get(route('admin.utilizadores.index'))
            ->assertRedirect(route('password.confirm'));
    }

    public function test_the_owner_creates_a_production_account(): void
    {
        $this->asOwner()
            ->post(route('admin.utilizadores.store'), $this->payload())
            ->assertRedirect(route('admin.utilizadores.index'));

        $staff = User::query()->where('email', 'rita@example.test')->sole();

        $this->assertFalse($staff->is_admin);
        $this->assertFalse($staff->is_owner);
        $this->assertSame(User::ROLE_PRODUCTION, $staff->staff_role);
        $this->assertTrue($staff->must_change_password);
        $this->assertNotNull($staff->email_verified_at);
        $this->assertTrue($staff->createdBy?->is($this->owner));
        $this->assertTrue(Hash::check(self::PASSWORD, $staff->password));

        Mail::assertQueued(StaffAccountChangedMail::class, fn ($mail) => $mail->hasTo('dono@12studio.test'));
    }

    public function test_the_owner_creates_an_admin_account(): void
    {
        $this->asOwner()->post(route('admin.utilizadores.store'), $this->payload(['role' => 'admin']));

        $staff = User::query()->where('email', 'rita@example.test')->sole();

        $this->assertTrue($staff->isAdmin());
        $this->assertNull($staff->staff_role);
        $this->assertFalse($staff->isOwner());
    }

    public function test_nobody_is_made_owner_through_the_form(): void
    {
        $this->asOwner()
            ->post(route('admin.utilizadores.store'), $this->payload(['role' => 'owner', 'is_owner' => true, 'is_admin' => true]))
            ->assertSessionHasErrors('role');

        $this->assertSame(1, User::query()->count());
    }

    public function test_a_customer_email_is_not_promoted(): void
    {
        User::factory()->create(['email' => 'cliente@example.test']);

        $this->asOwner()
            ->post(route('admin.utilizadores.store'), $this->payload(['email' => 'cliente@example.test']))
            ->assertSessionHasErrors('email');

        $this->assertTrue(User::query()->where('email', 'cliente@example.test')->sole()->is_admin === false);
    }

    public function test_the_initial_password_follows_the_app_rules(): void
    {
        $this->asOwner()
            ->post(route('admin.utilizadores.store'), $this->payload([
                'password' => '123',
                'password_confirmation' => '123',
            ]))
            ->assertSessionHasErrors('password');
    }

    public function test_the_owner_cannot_demote_or_disable_themselves(): void
    {
        $this->asOwner()
            ->patch(route('admin.utilizadores.update', $this->owner), [
                'name' => $this->owner->name,
                'email' => $this->owner->email,
                'role' => 'production',
            ])
            ->assertSessionHasErrors('staff');

        $this->asOwner()
            ->patch(route('admin.utilizadores.desativar', $this->owner))
            ->assertSessionHasErrors('staff');

        $this->owner->refresh();
        $this->assertTrue($this->owner->isOwner());
        $this->assertFalse($this->owner->isDisabled());
    }

    public function test_the_owner_edits_their_own_name(): void
    {
        $this->asOwner()
            ->patch(route('admin.utilizadores.update', $this->owner), [
                'name' => 'Dono Novo',
                'email' => $this->owner->email,
                'role' => 'owner',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Dono Novo', $this->owner->refresh()->name);
        $this->assertTrue($this->owner->isOwner());
    }

    public function test_the_owner_role_cannot_be_given_by_editing(): void
    {
        $admin = User::factory()->admin()->create();

        $this->asOwner()
            ->patch(route('admin.utilizadores.update', $admin), [
                'name' => $admin->name,
                'email' => $admin->email,
                'role' => 'owner',
            ])
            ->assertSessionHasErrors('role');

        $this->assertFalse($admin->refresh()->is_owner);
        $this->assertTrue($admin->isAdmin());
    }

    public function test_demoting_an_admin_to_production_takes_the_backoffice_away(): void
    {
        $admin = User::factory()->admin()->create();
        $rememberToken = $admin->getRememberToken();

        $this->asOwner()
            ->patch(route('admin.utilizadores.update', $admin), [
                'name' => $admin->name,
                'email' => $admin->email,
                'role' => 'production',
            ])
            ->assertSessionHasNoErrors();

        $admin->refresh();
        $this->assertFalse($admin->isAdmin());
        $this->assertTrue($admin->isProductionStaff());
        $this->assertNotSame($rememberToken, $admin->getRememberToken());

        $this->actingAs($admin)->get('/admin')->assertForbidden();
    }

    public function test_a_disabled_account_is_thrown_out_and_can_be_brought_back(): void
    {
        $admin = User::factory()->admin()->create();

        $this->asOwner()->patch(route('admin.utilizadores.desativar', $admin))->assertSessionHasNoErrors();

        $admin->refresh();
        $this->assertTrue($admin->isDisabled());
        $this->assertFalse($admin->isAdmin());

        $this->actingAs($admin)->get('/admin')->assertRedirect(route('login'));
        $this->assertGuest();

        $this->asOwner()->patch(route('admin.utilizadores.reativar', $admin))->assertSessionHasNoErrors();
        $this->assertTrue($admin->refresh()->isAdmin());
    }

    public function test_resetting_a_password_forces_a_new_one(): void
    {
        $staff = User::factory()->production()->create();

        $this->asOwner()
            ->put(route('admin.utilizadores.password', $staff), [
                'password' => self::PASSWORD,
                'password_confirmation' => self::PASSWORD,
            ])
            ->assertSessionHasNoErrors();

        $staff->refresh();
        $this->assertTrue($staff->must_change_password);
        $this->assertTrue(Hash::check(self::PASSWORD, $staff->password));
    }

    public function test_customers_are_not_managed_here(): void
    {
        $customer = User::factory()->create();

        $this->asOwner()
            ->patch(route('admin.utilizadores.desativar', $customer))
            ->assertNotFound();
    }

    public function test_staff_never_shows_up_as_a_customer(): void
    {
        $staff = User::factory()->production()->create(['name' => 'Rita da Produção']);
        User::factory()->create(['name' => 'Cliente Verdadeira']);

        $this->assertSame(['Cliente Verdadeira'], User::query()->customers()->pluck('name')->all());
        $this->assertNotContains($staff->name, array_column(ManualOrderOptions::customers(), 'name'));

        $this->actingAs($this->owner)
            ->get(route('admin.clientes.index'))
            ->assertInertia(fn (Assert $page) => $page->where('stats.total', 1));
    }

    public function test_staff_accounts_cannot_be_edited_as_customers(): void
    {
        $disabledAdmin = User::factory()->admin()->disabled()->create();

        $this->actingAs($this->owner)
            ->delete(route('admin.clientes.destroy', $disabledAdmin))
            ->assertNotFound();

        $this->assertModelExists($disabledAdmin);
    }

    public function test_a_manual_order_cannot_be_attached_to_a_staff_account(): void
    {
        $staff = User::factory()->production()->create();

        $this->actingAs($this->owner)
            ->post(route('admin.encomendas.store'), ['user_id' => $staff->id])
            ->assertSessionHasErrors('user_id');
    }

    public function test_staff_roles_are_not_mass_assignable(): void
    {
        $user = new User([
            'name' => 'X',
            'is_owner' => true,
            'is_admin' => true,
            'staff_role' => 'production',
            'must_change_password' => false,
            'disabled_at' => null,
        ]);

        $this->assertNull($user->getAttribute('is_owner'));
        $this->assertNull($user->getAttribute('is_admin'));
        $this->assertNull($user->getAttribute('staff_role'));
    }
}
