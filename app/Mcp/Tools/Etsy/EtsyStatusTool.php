<?php

namespace App\Mcp\Tools\Etsy;

use App\Etsy\EtsyApi;
use App\Etsy\EtsyClient;
use App\Etsy\EtsyException;
use App\Etsy\EtsyTokens;
use App\Etsy\EtsyWebhookSignature;
use App\Mcp\Tools\Etsy\Concerns\TalksToEtsy;
use App\Mcp\Tools\StudioTool;
use App\Models\EtsyConnection;
use App\Models\User;
use Illuminate\Support\Arr;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('etsy_status')]
#[Title('Estado da ligação à Etsy')]
#[Description('Diz se a loja da Etsy está ligada ao 12studio e em que estado: que loja e que conta, as permissões dadas, os dados públicos da loja (título, anúncio, moeda, nº de anúncios e de vendas), os limites de pedidos e o último erro. É por aqui que se começa quando outra ferramenta etsy_* recusa. Ligar ou religar a loja não se faz daqui: é no backoffice, em Definições → Etsy.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld]
class EtsyStatusTool extends StudioTool
{
    use TalksToEtsy;

    /** O que da loja interessa a quem pergunta "como esta a Etsy?". */
    private const SHOP_FIELDS = [
        'shop_id', 'shop_name', 'title', 'announcement', 'sale_message',
        'currency_code', 'url', 'is_vacation', 'vacation_message',
        'listing_active_count', 'digital_listing_count', 'transaction_sold_count',
        'review_average', 'review_count', 'num_favorers', 'shipping_from_country_iso',
        'policy_additional',
    ];

    public function __construct(
        private EtsyTokens $tokens,
        private EtsyApi $api,
        private EtsyClient $client,
    ) {}

    protected function etsy(Request $request, User $user): Response|ResponseFactory
    {
        $connection = $this->tokens->connection();
        $usable = $connection !== null
            && ! $connection->isBroken()
            && $this->tokens->isEnabled()
            && $this->tokens->isConfigured();

        $shop = null;
        $shopError = null;

        // Com a Etsy em baixo o estado continua a responder: e precisamente
        // quando ele mais falta faz.
        if ($usable) {
            try {
                $shop = Arr::only($this->api->shop($connection->shop_id), self::SHOP_FIELDS);
            } catch (EtsyException $exception) {
                $shopError = $exception->getMessage();
            }
        }

        return Response::structured([
            'message' => $this->summary($connection),
            'connected' => $connection !== null && ! $connection->isBroken(),
            'enabled' => $this->tokens->isEnabled(),
            'configured' => $this->tokens->isConfigured(),
            'connection' => $connection === null ? null : [
                'shop_id' => $connection->shop_id,
                'shop_name' => $connection->shop_name,
                'etsy_user_id' => $connection->etsy_user_id,
                'scopes' => $connection->scopeList(),
                'missing_scopes' => $connection->missingScopes(),
                'connected_at' => $connection->created_at?->format('Y-m-d H:i'),
                'orders_imported_since' => $connection->orders_since?->format('Y-m-d H:i'),
                'broken' => $connection->isBroken(),
                'last_error' => $connection->last_error,
            ],
            'shop' => $shop,
            'shop_error' => $shopError,
            'rate_limits' => $this->client->limits(),
            // Com os avisos da Etsy ligados as vendas entram em segundos; sem
            // eles entram na mesma, de 5 em 5 minutos.
            'orders_import' => [
                'webhook_configured' => app(EtsyWebhookSignature::class)->isConfigured(),
                'every_minutes' => 5,
            ],
        ]);
    }

    private function summary(?EtsyConnection $connection): string
    {
        return match (true) {
            ! $this->tokens->isConfigured() => EtsyException::notConfigured()->getMessage(),
            $connection === null => EtsyException::notConnected()->getMessage(),
            $connection->isBroken() => EtsyException::reconnect()->getMessage(),
            ! $this->tokens->isEnabled() => "A loja {$connection->shop_name} está ligada, mas a integração está desligada no servidor (ETSY_ENABLED=false): nada é enviado nem importado.",
            default => "Ligada à loja {$connection->shop_name}.",
        };
    }
}
