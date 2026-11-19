<?php

namespace App\Customers;

use App\Models\PromoCode;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Quem e quem pelo email. Os emails comparam-se normalizados (minusculas, sem
 * espacos) e as linhas antigas casam por `lower(email)`: uma conta criada com
 * "Ana@x.pt" tem de ser a mesma de quem compra com "ana@x.pt".
 *
 * A equipa nunca e cliente: nao se liga a compras e nao entra por ligacao.
 */
class CustomerDirectory
{
    /**
     * Com mais de um resultado (duas contas que so diferem nas maiusculas) nao
     * se adivinha: devolve null e avisa, sem o email, que e dado pessoal.
     */
    public function findByEmail(string $email): ?User
    {
        $normalized = PromoCode::normalizeEmail($email);

        if ($normalized === null) {
            return null;
        }

        $matches = $this->matches($normalized);

        if ($matches->count() > 1) {
            report(new RuntimeException('Mais de uma conta com o mesmo email (maiusculas diferentes).'));

            return null;
        }

        return $matches->first();
    }

    /**
     * @return Collection<int, User>
     */
    private function matches(string $normalized): Collection
    {
        return User::query()->whereRaw('lower(email) = ?', [$normalized])->limit(2)->get();
    }

    /**
     * A conta a que uma compra pertence. Um cliente que ja existe volta como
     * esta: comprar sem entrar nunca lhe altera nada. Se nao existe, nasce
     * com os dados da compra, sem email verificado e com uma password que
     * ninguem conhece.
     *
     * @param  array<string, mixed>  $customer
     * @param  array<string, mixed>  $address
     */
    public function forPurchase(string $email, array $customer, array $address): ?User
    {
        $normalized = PromoCode::normalizeEmail($email);

        if ($normalized === null) {
            return null;
        }

        $matches = $this->matches($normalized);

        // Ambiguo: nao se adivinha e tambem nao se cria (o indice unico
        // rebentava o aviso do Stripe). A encomenda fica sem conta.
        if ($matches->count() > 1) {
            report(new RuntimeException('Mais de uma conta com o mesmo email (maiusculas diferentes).'));

            return null;
        }

        $existing = $matches->first();

        if ($existing !== null) {
            return $existing->isStaff() ? null : $existing;
        }

        try {
            // Aninhada na transacao do aviso e por isso um savepoint: se o
            // create falhar, so ele recua e a encomenda segue.
            return DB::transaction(function () use ($normalized, $customer, $address): User {
                $user = User::query()->create([
                    'name' => $customer['name'],
                    'email' => $normalized,
                    'phone' => $customer['phone'],
                    'nif' => $customer['nif'] ?? null,
                    'password' => Hash::make(Str::password(32)),
                ]);

                $this->saveAddress($user, $address);

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            // Outro aviso criou a mesma conta entre a procura e o create:
            // passa a ser a que la esta.
            $created = $this->findByEmail($normalized);

            return $created === null || $created->isStaff() ? null : $created;
        }
    }

    /**
     * O que o cliente escreveu agora sobrepoe-se ao que estava: nome, telefone,
     * NIF e a morada por omissao (criada se ainda nao houver).
     *
     * @param  array<string, mixed>  $customer
     * @param  array<string, mixed>  $address
     */
    public function saveDetails(User $user, array $customer, array $address): void
    {
        DB::transaction(function () use ($user, $customer, $address): void {
            $user->update([
                'name' => $customer['name'],
                'phone' => $customer['phone'],
                'nif' => $customer['nif'] ?? null,
            ]);

            $this->saveAddress($user, $address);
        });
    }

    /**
     * @param  array<string, mixed>  $address
     */
    private function saveAddress(User $user, array $address): void
    {
        $user->addresses()->updateOrCreate(
            ['is_default' => true],
            [
                'name' => $address['name'],
                'line1' => $address['line1'],
                'line2' => $address['line2'] ?? null,
                'postal_code' => $address['postalCode'],
                'city' => $address['city'],
                'country' => $address['country'],
            ],
        );
    }
}
