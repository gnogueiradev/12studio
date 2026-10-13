<?php

namespace App\Mcp\Tools\Etsy;

use App\Etsy\EtsyShipping;
use App\Http\Requests\Etsy\SaveShippingDestinationRequest;
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

#[Name('etsy_shipping_destination_save')]
#[Title('Criar ou editar destino de envio')]
#[Description('Um destino de um perfil de envio: para onde (um país, ou uma região: eu, non_eu, none), por quanto (custo e custo por artigo adicional, em euros) e em quanto tempo (dias de entrega). É AQUI que se mudam os custos de envio. Com "destination_id" edita, sem ele acrescenta. Se o perfil for usado por anúncios à venda, a mudança altera já os portes que os compradores veem: a primeira chamada só avisa, e é preciso "confirm": true.')]
#[IsDestructive(false)]
#[IsOpenWorld]
class EtsyShippingDestinationSaveTool extends WriteTool
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
            'destination_id' => $schema->integer()->min(1)->description('Id do destino a editar. Sem isto, acrescenta um.'),
            'destination_country_iso' => $schema->string()->description('País de destino em duas letras (PT, ES, FR…). Em alternativa a destination_region.'),
            'destination_region' => $schema->string()->enum(['eu', 'non_eu', 'none'])->description('Região de destino: eu, non_eu, ou none (todo o lado).'),
            'primary_cost' => $schema->string()->required()->description('Custo de envio, em euros ("4,50").'),
            'secondary_cost' => $schema->string()->required()->description('Custo por cada artigo adicional ("0" se não houver).'),
            'min_delivery_days' => $schema->integer()->min(1)->max(45)->description('Dias mínimos de entrega.'),
            'max_delivery_days' => $schema->integer()->min(1)->max(45)->description('Dias máximos de entrega.'),
            'shipping_carrier_id' => $schema->integer()->description('Em alternativa aos dias: transportadora (ver etsy_shipping_list).'),
            'mail_class' => $schema->string()->description('Com a transportadora: a classe de correio dela.'),
            'confirm' => $schema->boolean()->description('Só depois de o utilizador confirmar o aviso da primeira resposta.'),
        ];
    }

    protected function etsy(Request $request, User $user): Response|ResponseFactory
    {
        $profileId = (int) $request->validate(['shipping_profile_id' => ['required', 'integer', 'min:1']])['shipping_profile_id'];
        $data = $this->runner->validate(SaveShippingDestinationRequest::class, $request->all(), $user);

        $active = $this->shipping->activeListingsUsingProfile($profileId);

        if ($active > 0 && ! $request->boolean('confirm')) {
            return $this->askFirst(sprintf(
                'O perfil de envio %d é usado por %s: mudar-lhe os destinos muda já os portes que os compradores veem (custo %s €, artigo adicional %s €).',
                $profileId,
                $this->activeListings($active),
                $data['primary_cost'],
                $data['secondary_cost'],
            ));
        }

        $destinationId = $this->shipping->saveDestination($profileId, $data);

        $this->changes = [
            'shipping_profile_id' => $profileId,
            'destination_id' => $destinationId,
            'created' => ! filled($data['destination_id'] ?? null),
            'active_listings' => $active,
        ];

        return Response::structured([
            'message' => 'Destino guardado na Etsy.',
            'shipping_profile_id' => $profileId,
            'destination_id' => $destinationId,
            'active_listings_affected' => $active,
        ]);
    }
}
