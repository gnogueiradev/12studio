<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Um cliente e um `User` com `is_admin = false` mais a sua morada unica.
 * Nao existe tabela `customers` — o plano quis clientes e admin na mesma
 * tabela para que, na Fase 5, o registo publico produza exatamente o mesmo
 * registo que o backoffice cria hoje.
 */
class CustomerService
{
    /**
     * O cliente criado no backoffice nao faz login: recebe uma password
     * aleatoria que ninguem ve nem recebe. Quando a Fase 5 abrir o registo,
     * entra por "recuperar password". `is_admin` esta fora do #[Fillable]
     * do User, por isso nao ha forma de promover alguem por aqui.
     *
     * @param  array<string, mixed>  $data
     */
    public function store(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            $customer = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make(Str::password(32)),
            ]);

            $customer->addresses()->create($this->addressAttributes($data, $customer->name));

            return $customer;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $customer, array $data): User
    {
        return DB::transaction(function () use ($customer, $data): User {
            $customer->update([
                'name' => $data['name'],
                'email' => $data['email'],
            ]);

            // 1 morada por cliente no V1: updateOrCreate em vez de create.
            $customer->addresses()->updateOrCreate(
                ['user_id' => $customer->getKey()],
                $this->addressAttributes($data, $customer->name),
            );

            return $customer->refresh();
        });
    }

    /**
     * Hard delete APENAS sem encomendas — a regra global de eliminacao
     * protege qualquer registo com historico comercial. Devolve false para
     * o controller poder explicar-se ao admin.
     */
    public function delete(User $customer): bool
    {
        if ($customer->orders()->exists()) {
            return false;
        }

        $customer->delete();

        return true;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function addressAttributes(array $data, string $fallbackName): array
    {
        return [
            'name' => $fallbackName,
            'line1' => $data['line1'],
            'line2' => $data['line2'] ?? null,
            'postal_code' => $data['postal_code'],
            'city' => $data['city'],
            'country' => $data['country'] ?? 'PT',
            'phone' => $data['phone'] ?? null,
            'nif' => $data['nif'] ?? null,
            'is_default' => true,
        ];
    }
}
