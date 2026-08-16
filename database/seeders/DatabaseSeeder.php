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
