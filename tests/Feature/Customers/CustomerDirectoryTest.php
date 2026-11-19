<?php

namespace Tests\Feature\Customers;

use App\Customers\CustomerDirectory;
use App\Models\Address;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use ReflectionMethod;
use Tests\TestCase;

/**
 * O diretorio de clientes: quem e quem pelo email, e o que uma compra faz (ou
 * nao faz) a uma conta existente. A equipa nunca e um cliente.
 */
class CustomerDirectoryTest extends TestCase
{
    use RefreshDatabase;

    /** @var array{name: string, email: string, phone: string, nif: string|null} */
    private array $customer = [
        'name' => 'Ana Silva',
        'email' => 'Ana@Exemplo.pt',
        'phone' => '912345678',
        'nif' => '123456789',
    ];

    /** @var array{name: string, line1: string, line2: string|null, postalCode: string, city: string, country: string, phone: string} */
    private array $address = [
        'name' => 'Ana Silva',
        'line1' => 'Rua das Flores 10',
        'line2' => null,
        'postalCode' => '4000-001',
        'city' => 'Porto',
        'country' => 'PT',
        'phone' => '912345678',
    ];

    public function test_find_by_email_ignores_case(): void
    {
        $user = User::factory()->create(['email' => 'Ana@Exemplo.pt']);

        $found = app(CustomerDirectory::class)->findByEmail(' ana@exemplo.PT ');

        $this->assertTrue($user->is($found));
    }

    public function test_find_by_email_refuses_an_ambiguous_match(): void
    {
        User::factory()->create()->forceFill(['email' => 'ana@x.pt'])->save();
        User::factory()->create()->forceFill(['email' => 'ANA@x.pt'])->save();

        $this->assertNull(app(CustomerDirectory::class)->findByEmail('ana@x.pt'));
    }

    public function test_for_purchase_creates_a_customer_with_the_purchase_details(): void
    {
        $user = app(CustomerDirectory::class)->forPurchase('Ana@Exemplo.pt', $this->customer, $this->address);

        $this->assertNotNull($user);
        $this->assertSame('ana@exemplo.pt', $user->email);
        $this->assertSame('Ana Silva', $user->name);
        $this->assertSame('912345678', $user->phone);
        $this->assertSame('123456789', $user->nif);
        $this->assertNull($user->email_verified_at);
        $this->assertFalse($user->isStaff());

        $address = $user->addresses()->sole();
        $this->assertTrue($address->is_default);
        $this->assertSame('Rua das Flores 10', $address->line1);
        $this->assertSame('4000-001', $address->postal_code);
        $this->assertSame('Porto', $address->city);
    }

    public function test_for_purchase_never_changes_an_existing_customer(): void
    {
        $existing = User::factory()->create(['email' => 'ana@exemplo.pt', 'name' => 'Ana Antiga', 'phone' => '900000000']);
        Address::factory()->create(['user_id' => $existing->id, 'line1' => 'Rua Antiga 1', 'is_default' => true]);

        $user = app(CustomerDirectory::class)->forPurchase('ana@exemplo.pt', $this->customer, $this->address);

        $this->assertTrue($existing->is($user));
        $existing->refresh();
        $this->assertSame('Ana Antiga', $existing->name);
        $this->assertSame('900000000', $existing->phone);
        $this->assertSame('Rua Antiga 1', $existing->addresses()->sole()->line1);
    }

    public function test_for_purchase_never_links_to_staff(): void
    {
        User::factory()->admin()->create(['email' => 'ana@exemplo.pt']);

        $this->assertNull(app(CustomerDirectory::class)->forPurchase('ana@exemplo.pt', $this->customer, $this->address));
        $this->assertSame(1, User::query()->count());
    }

    public function test_save_details_overwrites_the_details_and_creates_the_default_address(): void
    {
        $user = User::factory()->create(['name' => 'Antigo', 'phone' => '900000000', 'nif' => null]);

        app(CustomerDirectory::class)->saveDetails($user, $this->customer, $this->address);

        $user->refresh();
        $this->assertSame('Ana Silva', $user->name);
        $this->assertSame('912345678', $user->phone);
        $this->assertSame('123456789', $user->nif);
        $this->assertSame('Rua das Flores 10', $user->addresses()->where('is_default', true)->sole()->line1);

        $this->address['line1'] = 'Rua Nova 2';
        app(CustomerDirectory::class)->saveDetails($user, $this->customer, $this->address);

        $this->assertSame('Rua Nova 2', $user->addresses()->sole()->line1);
    }

    public function test_the_customer_guard_remembers_for_sixty_days(): void
    {
        // O metodo e protected no SessionGuard: le-se pela reflexao.
        $duration = new ReflectionMethod(Auth::guard('customer'), 'getRememberDuration');

        $this->assertSame(86400, $duration->invoke(Auth::guard('customer')));
    }
}
