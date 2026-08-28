<?php

namespace Database\Seeders;

use App\Models\PrinterProfile;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->seedAdmin();
        $this->seedPrinterProfiles();
    }

    /**
     * A impressora da casa. Sem ela a calculadora cai nos valores de recurso do
     * config/pricing.php e mostra um aviso — funciona, mas o dono nao tem onde
     * mexer nos numeros da maquina sem um deploy.
     *
     * Idempotente pelo nome, como o admin: o deploy corre este seeder sempre, e
     * numeros que o dono ja ajustou (mediu o consumo, comprou outra maquina)
     * nao podem voltar aos de fabrica a cada lancamento. Por isso quem mexeu
     * nas impressoras ja existentes foi uma migracao, e nao este seeder.
     */
    private function seedPrinterProfiles(): void
    {
        PrinterProfile::query()->firstOrCreate(
            ['name' => 'Bambu Lab A1'],
            [
                'average_power_watts' => 145,
                'purchase_price_cents' => 40_000,
                'lifetime_hours' => 4_000,
                'maintenance_micros_per_hour' => 40_000,
                'notes' => 'Consumo estimado; medir com um wattimetro quando der.',
                'is_default' => true,
                'active' => true,
                'sort_order' => 0,
            ],
        );
    }

    /**
     * Comprimento minimo da password do admin em producao. E o mesmo piso que
     * o AppServiceProvider poe no Password::defaults() — nao faria sentido a
     * app exigir 12 caracteres a quem muda a password e o seeder criar a
     * primeira conta com menos.
     */
    private const MINIMUM_PRODUCTION_LENGTH = 12;

    /**
     * Cria (ou promove) o administrador a partir de config/seeding.php.
     * Idempotente: correr o seeder em todos os deploys e seguro.
     */
    private function seedAdmin(): void
    {
        $email = (string) config('seeding.admin_email');
        $password = (string) config('seeding.admin_password');

        // Nunca existe um admin com password por omissao em producao — o
        // deploy rebenta aqui antes de criar um user inseguro (padrao qrcode).
        //
        // Isto ja estava escrito, e ja aqui estava; o que nao estava era a
        // funcionar. O config/seeding.php trazia '123' por omissao, e '123'
        // nunca e '', por isso esta condicao nunca era verdadeira e o deploy
        // seguia em frente com um admin de password trivial.
        if ($password === '' && app()->isProduction()) {
            throw new RuntimeException(
                'SEED_ADMIN_PASSWORD esta vazio: define-o no .env de producao antes de correr o seeder.'
            );
        }

        // Segunda guarda, para um erro de escrita no .env nao passar por uma
        // password a serio. O Jenkins corre `db:seed --force` em TODOS os
        // deploys — rebentar aqui e barato; um admin fraco em producao nao.
        if (app()->isProduction() && mb_strlen($password) < self::MINIMUM_PRODUCTION_LENGTH) {
            throw new RuntimeException(sprintf(
                'SEED_ADMIN_PASSWORD tem %d caracteres: em producao sao precisos pelo menos %d.',
                mb_strlen($password),
                self::MINIMUM_PRODUCTION_LENGTH,
            ));
        }

        // Fora de producao, sem password nao ha admin — e nao ha password de
        // recurso nenhuma a substitui-la. Ja era assim na pratica (o
        // .env.example traz SEED_ADMIN_PASSWORD vazio, e o '123' do config so
        // se aplicava quando a chave faltava por completo), e continua a ser:
        // quem quer entrar em dev poe a sua propria password no .env.
        if ($password === '') {
            return;
        }

        $user = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => (string) config('seeding.admin_name'),
                'password' => $password,
            ],
        );

        // is_admin e email_verified_at ficam os DOIS fora do #[Fillable] do
        // User — o primeiro de proposito (promover alguem a admin nunca pode
        // acontecer por mass-assignment de um request), o segundo por arrasto.
        //
        // O email_verified_at estava aqui em cima, no array do firstOrCreate, e
        // funcionava — mas por uma razao que nao esta escrita em lado nenhum: o
        // SeedCommand do Laravel corre os seeders dentro de Model::unguarded(),
        // por isso pelo `db:seed` do deploy a proteccao esta desligada e o
        // atributo passa.
        //
        // Chamado de qualquer outra maneira — (new DatabaseSeeder)->run() num
        // teste, ou de dentro de outro seeder — nao ha esse desligamento, e o
        // admin nascia por verificar. Como o backoffice inteiro esta atras de
        // ['auth', 'verified'] (routes/web.php), essa conta autenticava-se e
        // ficava presa na pagina de verificacao.
        //
        // Atribuir os dois diretamente tira a dependencia do unguarded: o
        // seeder passa a fazer o que diz, seja como for chamado.
        $user->is_admin = true;
        $user->email_verified_at ??= now();

        // Idempotente: a partir do segundo deploy nao ha nada sujo para gravar.
        if ($user->isDirty()) {
            $user->save();
        }
    }
}
