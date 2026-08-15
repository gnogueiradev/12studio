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
     * A impressora da casa. Sem ela a calculadora cai no custo/hora do
     * config/pricing.php e mostra um aviso — funciona, mas o dono nao tem onde
     * mudar a taxa sem um deploy.
     *
     * Idempotente pelo nome, como o admin: o deploy corre este seeder sempre, e
     * uma taxa que o dono ja ajustou nao pode voltar aos 0,20 EUR a cada
     * lancamento. Por isso quem moveu as impressoras ja existentes para a taxa
     * nova foi uma migracao, e nao este seeder.
     */
    private function seedPrinterProfiles(): void
    {
        PrinterProfile::query()->firstOrCreate(
            ['name' => 'Bambu Lab A1'],
            [
                'hourly_rate_cents' => 20,
                'notes' => 'Inclui energia, desgaste, manutencao e depreciacao.',
                'is_default' => true,
                'active' => true,
                'sort_order' => 0,
            ],
        );
    }

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
        if ($password === '' && app()->environment('production')) {
            throw new RuntimeException(
                'SEED_ADMIN_PASSWORD esta vazio: define-o no .env de producao antes de correr o seeder.'
            );
        }

        if ($password === '') {
            return;
        }

        $user = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => (string) config('seeding.admin_name'),
                'password' => $password,
                'email_verified_at' => now(),
            ],
        );

        // is_admin nao e fillable (protecao contra mass-assignment) —
        // atribuicao direta e deliberada, so aqui.
        if (! $user->is_admin) {
            $user->is_admin = true;
            $user->save();
        }
    }
}
