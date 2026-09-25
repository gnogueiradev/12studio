<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class McpInstallCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_the_personal_access_client_once(): void
    {
        $this->artisan('mcp:install')->assertSuccessful();
        $this->artisan('mcp:install')->assertSuccessful();

        $this->assertSame(1, Passport::client()->newQuery()->count());
    }
}
