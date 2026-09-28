<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountUsableTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_disabled_account_cannot_log_in(): void
    {
        $user = User::factory()->admin()->disabled()->create();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_an_active_account_still_logs_in(): void
    {
        $user = User::factory()->admin()->create();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_password_given_by_the_owner_must_be_changed_first(): void
    {
        $user = User::factory()->admin()->create();
        $user->must_change_password = true;
        $user->save();

        $this->actingAs($user)
            ->get('/admin')
            ->assertRedirect(route('security.edit'));

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('security.edit'))
            ->assertOk();
    }

    public function test_changing_the_password_frees_the_account(): void
    {
        $user = User::factory()->admin()->create();
        $user->must_change_password = true;
        $user->save();

        $this->actingAs($user)
            ->put(route('user-password.update'), [
                'current_password' => 'password',
                'password' => 'Uma-password-bem-longa-42',
                'password_confirmation' => 'Uma-password-bem-longa-42',
            ])
            ->assertSessionHasNoErrors();

        $this->assertFalse($user->refresh()->must_change_password);

        $this->actingAs($user)->get('/admin')->assertOk();
    }
}
