<?php

namespace App\Mcp\Tools\Etsy;

use App\Etsy\EtsyException;
use App\Etsy\EtsyShipping;
use App\Etsy\EtsyShop;
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
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[Name('etsy_delete')]
#[Title('Apagar da configuração da loja na Etsy')]
#[Description('Apaga, na Etsy, uma peça da configuração da loja. "kind" diz o quê: shipping_profile (perfil de envio), shipping_destination (um destino de um perfil), shipping_upgrade (um upgrade de um perfil), processing_profile (perfil de processamento), return_policy (política de devolução) ou section (secção da loja). Para destinos e upgrades indica também o "shipping_profile_id". Na Etsy isto NÃO se recupera, e por isso pede sempre confirmação: a primeira chamada só avisa, e é preciso "confirm": true. O que estiver em uso por anúncios (ou for a predefinição) é recusado mesmo confirmado, com a lista de quem o usa. Para apagar um ANÚNCIO, usa etsy_listing_delete.')]
#[IsDestructive]
#[IsIdempotent]
#[IsOpenWorld]
class EtsyDeleteTool extends WriteTool
{
    use AsksBeforeEtsy;
    use TalksToEtsy;

    /** kind => como se diz a quem pergunta. */
    private const KINDS = [
        'shipping_profile' => 'o perfil de envio',
        'shipping_destination' => 'o destino de envio',
        'shipping_upgrade' => 'o upgrade de envio',
        'processing_profile' => 'o perfil de processamento',
        'return_policy' => 'a política de devolução',
        'section' => 'a secção',
    ];

    public function __construct(
        private EtsyShipping $shipping,
        private EtsyShop $shop,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'kind' => $schema->string()->enum(array_keys(self::KINDS))->required()->description('O que apagar.'),
            'id' => $schema->integer()->min(1)->required()->description('O id do que se apaga (ver etsy_shipping_list ou etsy_sections_list).'),
            'shipping_profile_id' => $schema->integer()->min(1)->description('Só para shipping_destination e shipping_upgrade: o perfil a que pertencem.'),
            'confirm' => $schema->boolean()->description('Só depois de o utilizador confirmar o aviso da primeira resposta.'),
        ];
    }

    protected function etsy(Request $request, User $user): Response|ResponseFactory
    {
        $data = $request->validate([
            'kind' => ['required', 'in:'.implode(',', array_keys(self::KINDS))],
            'id' => ['required', 'integer', 'min:1'],
            'shipping_profile_id' => ['nullable', 'integer', 'min:1', 'required_if:kind,shipping_destination,shipping_upgrade'],
        ], [
            'shipping_profile_id.required_if' => 'Para apagar um destino ou um upgrade indica também o "shipping_profile_id" do perfil a que pertence.',
        ]);

        $kind = (string) $data['kind'];
        $id = (int) $data['id'];
        $profileId = (int) ($data['shipping_profile_id'] ?? 0);

        if (! $request->boolean('confirm')) {
            return $this->askFirst(sprintf(
                'Vais apagar %s %d da loja na Etsy. Lá não se recupera — só voltando a criar.%s',
                self::KINDS[$kind],
                $id,
                $kind === 'section' ? ' Os anúncios dessa secção ficam sem secção.' : '',
            ));
        }

        match ($kind) {
            'shipping_profile' => $this->shipping->deleteProfile($id),
            'shipping_destination' => $this->shipping->deleteDestination($profileId, $id),
            'shipping_upgrade' => $this->shipping->deleteUpgrade($profileId, $id),
            'processing_profile' => $this->shipping->deleteProcessingProfile($id),
            'return_policy' => $this->shop->deleteReturnPolicy($id),
            'section' => $this->shop->deleteSection($id),
            // A validacao acima ja so deixa passar os seis; isto e para um
            // tipo novo em KINDS que alguem se esqueca de tratar aqui dizer
            // que nao apagou nada, em vez de fingir que sim.
            default => throw new EtsyException("Não sei apagar \"{$kind}\". Nada foi apagado."),
        };

        $this->changes = array_filter([
            'kind' => $kind,
            'id' => $id,
            'shipping_profile_id' => $profileId ?: null,
            'deleted' => true,
        ], fn (mixed $value): bool => $value !== null);

        return Response::structured([
            'message' => ucfirst(self::KINDS[$kind])." {$id} foi apagado da Etsy.",
            'kind' => $kind,
            'id' => $id,
        ]);
    }
}
