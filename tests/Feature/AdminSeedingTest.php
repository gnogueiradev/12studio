<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

/**
 * O seeder do admin corre em TODOS os deploys (`db:seed --force` no
 * Jenkinsfile), por isso e ele que decide com que password nasce a unica conta
 * que existe na loja.
 *
 * A guarda "nunca ha admin com password por omissao" estava escrita no codigo e
 * no comentario, mas nao funcionava: o config/seeding.php trazia '123' por
 * omissao, e '123' nunca e '' — a condicao que devia rebentar o deploy nunca
 * chegava a ser verdadeira. Estes testes existem para isso nao voltar.
 */
class AdminSeedingTest extends TestCase
{
    use RefreshDatabase;

    private const REAL_PASSWORD = 'uma-password-mesmo-comprida';

    private function seedAsProduction(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->app->make(DatabaseSeeder::class)->run();
    }

    /**
     * O teste que apanha o bug original. Um default literal aqui — qualquer um,
     * nao so '123' — volta a anular as duas guardas abaixo, porque ambas
     * procuram o estado vazio que o default consumiria.
     */
    public function test_the_admin_password_has_no_default_value(): void
    {
        // Le o FICHEIRO e nao o config('...') resolvido: qualquer .env com a
        // SEED_ADMIN_PASSWORD definida — o desta maquina tem — tapava um
        // default que voltasse ao config/seeding.php, e o teste passava a
        // verde precisamente no caso que existe para apanhar.
        $source = file_get_contents(base_path('config/seeding.php'));

        $this->assertIsString($source);

        $this->assertMatchesRegularExpression(
            "/'admin_password'\s*=>\s*env\(\s*'SEED_ADMIN_PASSWORD'\s*\)/",
            $source,
            'config/seeding.php voltou a ter uma password por omissao: isso anula as guardas do DatabaseSeeder.',
        );
    }

    public function test_production_refuses_an_empty_password(): void
    {
        config(['seeding.admin_password' => '']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SEED_ADMIN_PASSWORD esta vazio');

        $this->seedAsProduction();
    }

    public function test_production_refuses_a_short_password(): void
    {
        // O caso de um erro de escrita no .env: nao esta vazio, mas tambem nao
        // e uma password.
        config(['seeding.admin_password' => '123']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('em producao sao precisos pelo menos 12');

        $this->seedAsProduction();
    }

    public function test_production_creates_no_user_when_it_refuses(): void
    {
        config(['seeding.admin_password' => '123']);

        try {
            $this->seedAsProduction();
        } catch (RuntimeException) {
            // O que interessa e o estado da tabela, nao a excecao.
        }

        $this->assertSame(0, User::query()->count(), 'O seeder rebentou mas deixou um user para tras.');
    }

    public function test_no_admin_is_created_without_a_password_outside_production(): void
    {
        config(['seeding.admin_password' => '']);

        $this->app->make(DatabaseSeeder::class)->run();

        $this->assertSame(0, User::query()->count());
    }

    public function test_a_real_password_creates_the_admin(): void
    {
        config(['seeding.admin_password' => self::REAL_PASSWORD]);

        $this->seedAsProduction();

        $admin = User::query()->where('email', config('seeding.admin_email'))->sole();

        $this->assertTrue($admin->isAdmin());
        $this->assertTrue(Hash::check(self::REAL_PASSWORD, $admin->password));
        $this->assertNotNull($admin->email_verified_at);
    }

    /**
     * O teste que faltava — e que deixou passar um admin inutilizavel.
     *
     * O email_verified_at ia no array do firstOrCreate mas nao esta no
     * #[Fillable] do User, por isso era descartado em silencio. A conta nascia
     * por verificar, e o backoffice inteiro esta atras de ['auth', 'verified']:
     * o unico utilizador da loja autenticava-se e ficava preso na pagina de
     * verificacao. Nenhum teste dava por isso porque todos os outros criam
     * users pela factory, que verifica o email.
     */
    public function test_the_seeded_admin_can_reach_the_backoffice(): void
    {
        config(['seeding.admin_password' => self::REAL_PASSWORD]);

        $this->app->make(DatabaseSeeder::class)->run();

        $admin = User::query()->where('email', config('seeding.admin_email'))->sole();

        $this->assertNotNull($admin->email_verified_at, 'O admin nasceu com o email por verificar.');

        $this->actingAs($admin)->get('/admin')->assertOk();
    }

    public function test_seeding_twice_leaves_one_admin(): void
    {
        // O Jenkins corre o seeder a cada deploy: se nao fosse idempotente,
        // o segundo lancamento rebentava contra o indice unico do email.
        config(['seeding.admin_password' => self::REAL_PASSWORD]);

        $this->seedAsProduction();
        $this->app->make(DatabaseSeeder::class)->run();

        $this->assertSame(1, User::query()->count());
    }
}
