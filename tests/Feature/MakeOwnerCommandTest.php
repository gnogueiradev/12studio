<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MakeOwnerCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_makes_an_existing_account_the_owner(): void
    {
        $user = User::factory()->create(['email' => 'goncaloe6@hotmail.com']);

        $this->artisan('users:make-owner', ['email' => 'GoncaloE6@hotmail.com'])
            ->assertSuccessful();

        $user->refresh();
        $this->assertTrue($user->isOwner());
        $this->assertTrue($user->isAdmin());
    }

    public function test_it_refuses_an_unknown_email(): void
    {
        $this->artisan('users:make-owner', ['email' => 'ninguem@example.test'])
            ->assertFailed();
    }

    public function test_it_refuses_a_disabled_account(): void
    {
        $user = User::factory()->disabled()->create();

        $this->artisan('users:make-owner', ['email' => $user->email])
            ->assertFailed();

        $this->assertFalse($user->refresh()->is_owner);
    }
}
