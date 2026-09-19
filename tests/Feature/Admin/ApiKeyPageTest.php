<?php

namespace Tests\Feature\Admin;

use App\Mail\ApiKeyCreatedMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Passport\ClientRepository;
use Tests\TestCase;

class ApiKeyPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        app(ClientRepository::class)->createPersonalAccessGrantClient('Chaves de API do 12studio', 'users');
    }

    private function confirmed(User $user): static
    {
        return $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
    }

    public function test_the_page_asks_for_the_password_again(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.chaves-api.index'))
            ->assertRedirect(route('password.confirm'));
    }

    public function test_customers_and_production_cannot_open_it(): void
    {
        $this->confirmed(User::factory()->create())
            ->get(route('admin.chaves-api.index'))
            ->assertForbidden();

        $this->confirmed(User::factory()->production()->create())
            ->get(route('admin.chaves-api.index'))
            ->assertForbidden();
    }

    public function test_without_a_second_factor_no_key_is_created(): void
    {
        $admin = User::factory()->admin()->create();

        $this->confirmed($admin)
            ->post(route('admin.chaves-api.store'), ['name' => 'Portátil', 'access' => 'write', 'days' => 30])
            ->assertRedirect();

        $this->assertSame(0, $admin->tokens()->count());
    }

    public function test_an_admin_with_2fa_creates_a_key_and_sees_it_once(): void
    {
        $admin = User::factory()->admin()->withTwoFactor()->create();

        $this->confirmed($admin)
            ->post(route('admin.chaves-api.store'), ['name' => 'Portátil', 'access' => 'write', 'days' => 30])
            ->assertRedirect(route('admin.chaves-api.index'));

        $key = $admin->tokens()->sole();
        $this->assertSame(['mcp:read', 'mcp:write'], $key->scopes);
        $this->assertTrue($key->expires_at->between(now()->addDays(29), now()->addDays(31)));

        Mail::assertQueued(ApiKeyCreatedMail::class);

        // O pedido a seguir a criacao mostra o token...
        $this->confirmed($admin)
            ->withSession(['api_key_token' => 'token-de-exemplo'])
            ->get(route('admin.chaves-api.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('createdToken', 'token-de-exemplo')
                ->has('keys', 1));

        // ...e o seguinte ja nao.
        $this->confirmed($admin)
            ->get(route('admin.chaves-api.index'))
            ->assertInertia(fn (Assert $page) => $page->where('createdToken', null));
    }

    public function test_the_lifetime_is_one_of_the_allowed_ones(): void
    {
        $admin = User::factory()->admin()->withTwoFactor()->create();

        $this->confirmed($admin)
            ->post(route('admin.chaves-api.store'), ['name' => 'X', 'access' => 'read', 'days' => 3650])
            ->assertSessionHasErrors('days');
    }

    public function test_an_admin_cannot_revoke_someone_elses_key(): void
    {
        $other = User::factory()->admin()->create();
        $key = $other->createToken('Alheia', ['mcp:read'])->token;

        $this->confirmed(User::factory()->admin()->create())
            ->delete(route('admin.chaves-api.destroy', $key->id));

        $this->assertFalse($key->refresh()->revoked);
    }

    public function test_revoke_all(): void
    {
        $admin = User::factory()->admin()->create();
        $admin->createToken('A', ['mcp:read']);
        $admin->createToken('B', ['mcp:read']);

        $this->confirmed($admin)->delete(route('admin.chaves-api.destroy-all'));

        $this->assertSame(0, $admin->tokens()->where('revoked', false)->count());
    }
}
