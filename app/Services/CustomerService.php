<?php

namespace App\Services;

use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Um cliente e um `User` com `is_admin = false` e, quando ha, uma morada de
 * envio. Nao existe tabela `customers` — o plano quis clientes e admin na
 * mesma tabela para que, na Fase 5, o registo publico produza exatamente o
 * mesmo registo que o backoffice cria hoje.
 *
 * A morada e opcional: no backoffice cria-se um cliente so com o nome. Telefone
 * e NIF vivem em `users` precisamente por isso — sao da pessoa, e sem eles la
 * um cliente sem morada nao teria onde guardar o NIF.
 */
class CustomerService
{
    public function __construct(
        private TagService $tagService,
    ) {}

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
                ...$this->customerAttributes($data),
                'password' => Hash::make(Str::password(32)),
            ]);

            $this->syncAddress($customer, $data);
            $this->syncTags($customer, $this->pullTags($data) ?? []);

            return $customer;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $customer, array $data): User
    {
        return DB::transaction(function () use ($customer, $data): User {
            $tags = $this->pullTags($data);

            $customer->update($this->customerAttributes($data));

            $this->syncAddress($customer, $data);

            // Null e "as etiquetas nao vieram no pedido" — nao mexer. Array
            // vazio e "vieram vazias" — limpar. Mesma distincao do produto.
            if ($tags !== null) {
                $this->syncTags($customer, $tags);
            }

            return $customer->refresh();
        });
    }

    /**
     * @param  array<int, string>  $names
     */
    public function syncTags(User $customer, array $names): void
    {
        $customer->tags()->sync($this->tagService->idsFor(Tag::SCOPE_CUSTOMER, $names));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>|null
     */
    private function pullTags(array $data): ?array
    {
        if (! array_key_exists('tags', $data)) {
            return null;
        }

        /** @var array<int, string> $tags */
        $tags = $data['tags'] ?? [];

        return $tags;
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
    private function customerAttributes(array $data): array
    {
        return [
            'name' => $data['name'],
            // String vazia vinda do formulario tem de virar null: a coluna e
            // unica, e dois clientes sem email com '' colidiam.
            'email' => $this->nullIfBlank($data['email'] ?? null),
            'customer_type' => $data['customer_type'],
            'phone' => $this->nullIfBlank($data['phone'] ?? null),
            'nif' => $this->nullIfBlank($data['nif'] ?? null),
            'admin_note' => $this->nullIfBlank($data['admin_note'] ?? null),
        ];
    }

    /**
     * 1 morada por cliente no V1. Quando o admin esvazia a morada, a linha
     * desaparece — um registo em `addresses` sem rua nao e uma morada, e a
     * tabela nem sequer o aceita.
     *
     * @param  array<string, mixed>  $data
     */
    private function syncAddress(User $customer, array $data): void
    {
        if ($this->nullIfBlank($data['line1'] ?? null) === null) {
            $customer->addresses()->delete();

            return;
        }

        $customer->addresses()->updateOrCreate(
            ['user_id' => $customer->getKey()],
            [
                'name' => $customer->name,
                'line1' => $data['line1'],
                'line2' => $this->nullIfBlank($data['line2'] ?? null),
                'postal_code' => $data['postal_code'],
                'city' => $data['city'],
                'country' => $this->nullIfBlank($data['country'] ?? null) ?? 'PT',
                'is_default' => true,
            ],
        );
    }

    private function nullIfBlank(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : trim($value);
    }
}
