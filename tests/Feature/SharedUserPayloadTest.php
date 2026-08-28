<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * O `auth.user` do HandleInertiaRequests vai no data-page do HTML de TODAS as
 * paginas e em todas as respostas do Inertia. Era o modelo User inteiro: o
 * #[Hidden] tapava a password e os segredos do 2FA, mas nao o phone, o nif, o
 * admin_note nem o is_admin.
 *
 * O nif e dado pessoal (RGPD) e o admin_note e, por definicao, o que a loja
 * escreve sobre alguem para nao lho mostrar.
 */
class SharedUserPayloadTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string}>
     */
    public static function fieldsThatMustNotLeak(): array
    {
        return [
            'telefone' => ['phone'],
            'nif' => ['nif'],
            'nota interna' => ['admin_note'],
            'tipo de cliente' => ['customer_type'],
            'estatuto de admin' => ['is_admin'],
            'password' => ['password'],
            'segredo do 2fa' => ['two_factor_secret'],
            'codigos de recuperacao' => ['two_factor_recovery_codes'],
            'token de sessao persistente' => ['remember_token'],
        ];
    }

    #[DataProvider('fieldsThatMustNotLeak')]
    public function test_the_field_is_absent_from_the_shared_user(string $field): void
    {
        $admin = User::factory()->admin()->create([
            'phone' => '910000000',
            'nif' => '123456789',
            'admin_note' => 'Nota que so a loja pode ler.',
        ]);

        $shared = $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->viewData('page')['props']['auth']['user'];

        $this->assertArrayNotHasKey(
            $field,
            $shared,
            "O campo `{$field}` voltou ao auth.user: vai no HTML de todas as paginas.",
        );
    }

    public function test_the_shared_user_carries_exactly_what_the_frontend_reads(): void
    {
        // A lista fechada e o ponto: um campo novo aqui tem de ser uma decisao,
        // e nao o efeito lateral de acrescentar uma coluna aos users.
        $shared = $this->actingAs(User::factory()->admin()->create())
            ->get('/admin')
            ->assertOk()
            ->viewData('page')['props']['auth']['user'];

        $this->assertSame(
            ['id', 'name', 'email', 'email_verified_at'],
            array_keys($shared),
        );
    }

    public function test_a_guest_shares_no_user_at_all(): void
    {
        $shared = $this->get('/')
            ->assertOk()
            ->viewData('page')['props']['auth']['user'];

        $this->assertNull($shared);
    }
}
