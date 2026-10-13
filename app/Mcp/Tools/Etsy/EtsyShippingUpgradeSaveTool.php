<?php

namespace App\Mcp\Tools\Etsy;

use App\Etsy\EtsyShipping;
use App\Http\Requests\Etsy\SaveShippingUpgradeRequest;
use App\Mcp\FormRequestRunner;
use App\Mcp\Tools\Etsy\Concerns\AsksBeforeEtsy;
use App\Mcp\Tools\Etsy\Concerns\TalksToEtsy;
use App\Mcp\Tools\WriteTool;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[Name('etsy_shipping_upgrade_save')]
#[Title('Criar ou editar upgrade de envio')]
#[Description('Um upgrade de envio de um perfil: a opção mais rápida que o comprador pode escolher no checkout (ex. "Expresso"), nacional ("domestic") ou internacional ("international"), com o preço e o preço por artigo adicional, em euros. Com "upgrade_id" edita, sem ele acrescenta. Se o perfil for usado por anúncios à venda, os compradores passam a ver a opção já: a primeira chamada só avisa, e é preciso "confirm": true.')]
#[IsDestructive(false)]
#[IsOpenWorld]
class EtsyShippingUpgradeSaveTool extends WriteTool
{
    use AsksBeforeEtsy;
    use TalksToEtsy;

    public function __construct(
        private EtsyShipping $shipping,
        private FormRequestRunner $runner,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'shipping_profile_id' => $schema->integer()->min(1)->required()->description('Id do perfil de envio (ver etsy_shipping_list).'),
            'upgrade_id' => $schema->integer()->min(1)->description('Id do upgrade a editar. Sem isto, acrescenta um.'),
            'upgrade_name' => $schema->string()->max(100)->required()->description('Nome que o comprador vê (ex. "Expresso").'),
            'type' => $schema->string()->enum(['domestic', 'international'])->required()->description('domestic (nacional) ou international.'),
            'price' => $schema->string()->required()->description('Preço do upgrade, em euros ("3,00").'),
            'secondary_price' => $schema->string()->required()->description('Preço por cada artigo adicional ("0" se não houver).'),
            'min_delivery_days' => $schema->integer()->min(1)->max(45)->description('Dias mínimos de entrega.'),
            'max_delivery_days' => $schema->integer()->min(1)->max(45)->description('Dias máximos de entrega.'),
            'confirm' => $schema->boolean()->description('Só depois de o utilizador confirmar o aviso da primeira resposta.'),
        ];
    }

    protected function etsy(Request $request, User $user): Response|ResponseFactory
    {
        $profileId = (int) $request->validate(['shipping_profile_id' => ['required', 'integer', 'min:1']])['shipping_profile_id'];
        $data = $this->runner->validate(SaveShippingUpgradeRequest::class, $request->all(), $user);

        $active = $this->shipping->activeListingsUsingProfile($profileId);

        if ($active > 0 && ! $request->boolean('confirm')) {
            return $this->askFirst(sprintf(
                'O perfil de envio %d é usado por %s: o upgrade "%s" (%s €) passa a aparecer já no checkout desses anúncios.',
                $profileId,
                $this->activeListings($active),
                $data['upgrade_name'],
                $data['price'],
            ));
        }

        $upgradeId = $this->shipping->saveUpgrade($profileId, $data);

        $this->changes = [
            'shipping_profile_id' => $profileId,
            'upgrade_id' => $upgradeId,
            'created' => ! filled($data['upgrade_id'] ?? null),
            'active_listings' => $active,
        ];

        return Response::structured([
            'message' => 'Upgrade guardado na Etsy.',
            'shipping_profile_id' => $profileId,
            'upgrade_id' => $upgradeId,
            'active_listings_affected' => $active,
        ]);
    }
}
