<?php

namespace Tests\Unit;

use App\Mcp\McpAuditor;
use PHPUnit\Framework\TestCase;

class McpAuditorTest extends TestCase
{
    public function test_secrets_never_reach_the_log(): void
    {
        $clean = (new McpAuditor)->sanitize([
            'name' => 'Vaso',
            'password' => 'segredo',
            'nested' => ['token' => 'abc', 'ok' => 1],
        ]);

        $this->assertSame(['name' => 'Vaso', 'nested' => ['ok' => 1]], $clean);
    }

    public function test_personal_data_is_masked(): void
    {
        $clean = (new McpAuditor)->sanitize([
            'email' => 'goncalo@hotmail.com',
            'phone' => '912345612',
            'nif' => '123456789',
        ]);

        $this->assertSame('g***@hotmail.com', $clean['email']);
        $this->assertSame('9******12', $clean['phone']);
        $this->assertSame('1******89', $clean['nif']);
    }

    public function test_long_text_is_cut(): void
    {
        $clean = (new McpAuditor)->sanitize(['description' => str_repeat('a', 1000)]);

        $this->assertLessThanOrEqual(303, mb_strlen($clean['description']));
    }
}
