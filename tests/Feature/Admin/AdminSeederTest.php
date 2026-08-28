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

            $this->runSeederDirectly();
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

    /**
     * O config/seeding.php tinha '123' como password por omissao, e isso
     * anulava o guard do teste acima: ele procura o estado vazio, e um default
     * garante que o valor nunca esta vazio. Um default e uma validacao sobre o
     * mesmo valor sao inimigos.
     *
     * Le o FICHEIRO e nao o config() resolvido: qualquer .env com a
     * SEED_ADMIN_PASSWORD definida — o desta maquina tem — tapava um default
     * que voltasse ao sitio, e o teste passava a verde precisamente no caso que
     * existe para apanhar.
     */
    public function test_the_admin_password_has_no_default_value(): void
    {
        $source = file_get_contents(base_path('config/seeding.php'));

        $this->assertIsString($source);

        $this->assertMatchesRegularExpression(
            "/'admin_password'\s*=>\s*env\(\s*'SEED_ADMIN_PASSWORD'\s*\)/",
            $source,
            'config/seeding.php voltou a ter uma password por omissao: isso anula as guardas do DatabaseSeeder.',
        );
    }

    /**
     * Segunda guarda: um erro de escrita no .env nao pode passar por uma
     * password a serio. O piso e o mesmo que o AppServiceProvider poe no
     * Password::defaults() em producao.
     */
    public function test_seeder_fails_in_production_with_a_short_password(): void
    {
        config(['seeding.admin_password' => '123']);
        $this->app['env'] = 'production';

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('em producao sao precisos pelo menos 12');

            $this->runSeederDirectly();
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_a_refused_seed_leaves_no_user_behind(): void
    {
        config(['seeding.admin_password' => '123']);
        $this->app['env'] = 'production';

        try {
            $this->runSeederDirectly();
        } catch (RuntimeException) {
            // O que interessa e o estado da tabela, nao a excecao.
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->assertSame(0, User::query()->count());
    }

    /**
     * O admin tem de nascer verificado seja como for que o seeder e chamado.
     *
     * O email_verified_at nao esta no #[Fillable] do User, por isso passa-lo no
     * array do firstOrCreate so funcionava por um efeito lateral do comando: o
     * SeedCommand corre os seeders dentro de Model::unguarded(). Pelo `db:seed`
     * do deploy passava; chamado de qualquer outra maneira, criava um admin por
     * verificar — e o backoffice esta todo atras de ['auth', 'verified'].
     *
     * Por isso este teste usa a invocacao DIRETA, que e a que nao tem a rede de
     * seguranca: se alguem devolver o atributo ao firstOrCreate, e aqui que
     * rebenta.
     */
    public function test_the_admin_is_verified_without_relying_on_unguarded_models(): void
    {
        config(['seeding.admin_email' => 'dono@12studio.test']);
        config(['seeding.admin_password' => 'segredo-forte']);

        $this->runSeederDirectly();

        $admin = User::query()->where('email', 'dono@12studio.test')->sole();

        $this->assertNotNull(
            $admin->email_verified_at,
            'O admin nasceu por verificar: fica preso na pagina de verificacao, sem acesso ao backoffice.',
        );

        $this->actingAs($admin)->get('/admin')->assertOk();
    }

    /**
     * Sem passar pelo comando: o db:seed pede confirmacao interativa em
     * producao (ConfirmableTrait) e envolve tudo em Model::unguarded(), duas
     * coisas que escondem o que estes testes querem ver.
     */
    private function runSeederDirectly(): void
    {
        $seeder = new DatabaseSeeder;
        $seeder->setContainer($this->app);
        $seeder->run();
    }
}
