<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class AdminSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_the_admin_from_config(): void
    {
        config(['seeding.admin_email' => 'dono@12studio.test']);
        config(['seeding.admin_password' => 'segredo-forte']);

        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'dono@12studio.test')->firstOrFail();

        $this->assertTrue($admin->isAdmin());
        $this->assertNotNull($admin->email_verified_at);
    }

    public function test_seeder_is_idempotent_and_promotes_existing_user(): void
    {
        config(['seeding.admin_email' => 'dono@12studio.test']);
        config(['seeding.admin_password' => 'segredo-forte']);

        User::factory()->create(['email' => 'dono@12studio.test']);

        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, User::query()->where('email', 'dono@12studio.test')->count());
        $this->assertTrue(User::query()->where('email', 'dono@12studio.test')->firstOrFail()->isAdmin());
    }

    public function test_seeder_fails_in_production_without_password(): void
    {
        config(['seeding.admin_password' => '']);
        $this->app['env'] = 'production';

        try {
            $this->expectException(RuntimeException::class);

            // Invocado diretamente (nao via comando db:seed): em producao o
            // ConfirmableTrait do artisan pediria confirmacao interativa antes
            // de o nosso guard sequer correr.
            $seeder = new DatabaseSeeder;
            $seeder->setContainer($this->app);
            $seeder->run();
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_seeder_skips_quietly_without_password_outside_production(): void
    {
        config(['seeding.admin_password' => '']);

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, User::query()->count());
    }
}
