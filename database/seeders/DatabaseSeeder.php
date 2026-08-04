<?php

namespace Database\Seeders;

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
