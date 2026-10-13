<?php

namespace App\Mcp\Tools\Etsy;

use App\Etsy\EtsySettings;
use App\Etsy\EtsyShipping;
use App\Etsy\EtsyShop;
use App\Mcp\Tools\Etsy\Concerns\TalksToEtsy;
use App\Mcp\Tools\StudioTool;
use App\Models\User;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('etsy_shipping_list')]
#[Title('Envios e devoluções na Etsy')]
#[Description('Tudo o que a loja tem configurado na Etsy para enviar: os perfis de envio (cada um com os destinos — onde vivem os custos — e os upgrades), os perfis de processamento (pronto a enviar / feito por encomenda), as políticas de devolução, as transportadoras que a Etsy conhece a sair de Portugal, e quais são as predefinições do 12studio. Cada perfil diz quantos anúncios o usam e quantos desses estão à venda ("active_listings"): mexer nos custos de um perfil com anúncios ativos muda já o que os compradores pagam.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld]
class EtsyShippingListTool extends StudioTool
{
    use TalksToEtsy;

    public function __construct(
        private EtsyShipping $shipping,
        private EtsyShop $shop,
        private EtsySettings $settings,
    ) {}

    protected function etsy(Request $request, User $user): Response|ResponseFactory
    {
        return Response::structured([
            'shipping_profiles' => $this->shipping->profiles(),
            'processing_profiles' => $this->shipping->processingProfiles(),
            'return_policies' => $this->shop->returnPolicies(),
            'carriers' => $this->shipping->carriers(),
            'defaults' => $this->settings->defaults(),
            'notes' => [
                'Os custos de envio vivem nos destinos (etsy_shipping_destination_save); editar um perfil só muda o título e a origem.',
                'Preços em euros como texto ("4,50"). Prazos de entrega em dias.',
            ],
        ]);
    }
}
