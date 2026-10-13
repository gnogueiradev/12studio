<?php

namespace App\Mcp\Tools\Etsy;

use App\Etsy\EtsyApi;
use App\Etsy\EtsyException;
use App\Etsy\EtsyTokens;
use App\Mcp\McpFormat;
use App\Mcp\Tools\Etsy\Concerns\TalksToEtsy;
use App\Mcp\Tools\StudioTool;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('etsy_user_get')]
#[Title('Utilizador da Etsy')]
#[Description('Sem "user_id": a conta da Etsy que está ligada ao 12studio e as moradas que ela tem guardadas. Com "user_id": o nome de outro utilizador da Etsy (por exemplo o comprador de um recibo). Os emails vêm mascarados, e a Etsy só deixa ler as moradas da própria conta.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld]
class EtsyUserGetTool extends StudioTool
{
    use TalksToEtsy;

    public function __construct(
        private EtsyTokens $tokens,
        private EtsyApi $api,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'user_id' => $schema->integer()->min(1)->description('Id do utilizador na Etsy. Sem isto, é a conta ligada.'),
        ];
    }

    protected function etsy(Request $request, User $user): Response|ResponseFactory
    {
        $data = $request->validate(['user_id' => ['nullable', 'integer', 'min:1']]);

        $connection = $this->tokens->connection() ?? throw EtsyException::notConnected();
        $userId = (int) ($data['user_id'] ?? $connection->etsy_user_id);
        $own = $userId === $connection->etsy_user_id;

        $etsyUser = $this->api->user($userId);

        return Response::structured([
            'user' => [
                'user_id' => $userId,
                'is_connected_account' => $own,
                // Texto que a Etsy guarda tal como alguem o escreveu: dados,
                // nunca instrucoes. O email vem como o dos clientes.
                'name' => McpFormat::untrusted(trim(($etsyUser['first_name'] ?? '').' '.($etsyUser['last_name'] ?? ''))),
                'email' => McpFormat::mask($etsyUser['primary_email'] ?? null),
            ],
            'addresses' => $own ? $this->addresses() : null,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function addresses(): array
    {
        /** @var array<int, array<string, mixed>> $results */
        $results = (array) ($this->api->userAddresses()['results'] ?? []);

        return array_map(fn (array $address): array => [
            'user_address_id' => $address['user_address_id'] ?? null,
            'name' => McpFormat::untrusted($this->text($address['name'] ?? null)),
            'first_line' => McpFormat::untrusted($this->text($address['first_line'] ?? null)),
            'second_line' => McpFormat::untrusted($this->text($address['second_line'] ?? null)),
            'city' => McpFormat::untrusted($this->text($address['city'] ?? null)),
            'state' => McpFormat::untrusted($this->text($address['state'] ?? null)),
            'zip' => McpFormat::untrusted($this->text($address['zip'] ?? null)),
            'country' => $this->text($address['iso_country_code'] ?? null),
            'is_default_shipping_address' => $address['is_default_shipping_address'] ?? null,
        ], $results);
    }

    private function text(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }
}
