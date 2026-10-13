<?php

namespace App\Mcp\Tools\Etsy;

use App\Etsy\EtsyShipping;
use App\Http\Requests\Etsy\AssignShippingProfileRequest;
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
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[Name('etsy_shipping_assign')]
#[Title('Dar um perfil de envio a produtos')]
#[Description('Dá um perfil de envio a um ou a vários produtos de uma vez. O anúncio que já existe na Etsy muda lá na hora; o produto que ainda não tem anúncio fica só com o perfil escolhido, para quando for enviado. Se algum dos produtos já estiver à venda, a troca muda já os portes que os compradores veem: a primeira chamada só avisa quais são, e é preciso "confirm": true. A resposta diz, produto a produto, o que mudou, o que ficou preparado e o que a Etsy recusou.')]
#[IsDestructive(false)]
#[IsIdempotent]
#[IsOpenWorld]
class EtsyShippingAssignTool extends WriteTool
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
            'product_ids' => $schema->array()->items($schema->integer())->required()->description('Ids dos produtos do 12studio (ver products_list).'),
            'confirm' => $schema->boolean()->description('Só depois de o utilizador confirmar o aviso da primeira resposta.'),
        ];
    }

    protected function etsy(Request $request, User $user): Response|ResponseFactory
    {
        $profileId = (int) $request->validate(['shipping_profile_id' => ['required', 'integer', 'min:1']])['shipping_profile_id'];
        $data = $this->runner->validate(AssignShippingProfileRequest::class, $request->all(), $user);

        /** @var array<int, int> $productIds */
        $productIds = array_map('intval', (array) $data['product_ids']);

        if (! $this->shipping->profileExists($profileId)) {
            return Response::error("O perfil de envio {$profileId} não existe na loja. Vê os que há com etsy_shipping_list.");
        }

        $live = $this->shipping->activeAmong($productIds);

        if ($live !== [] && ! $request->boolean('confirm')) {
            return $this->askFirst(sprintf(
                'Estes produtos já estão à venda na Etsy: %s. Trocar-lhes o perfil de envio muda já os portes que os compradores veem.',
                implode(', ', $live),
            ));
        }

        $result = $this->shipping->assign($productIds, $profileId);

        $this->changes = ['shipping_profile_id' => $profileId, ...$result];

        if ($result['updated'] === [] && $result['prepared'] === [] && $result['failed'] !== []) {
            return Response::error('A Etsy recusou a troca em todos os produtos: '.implode(' | ', array_map(
                fn (string $name, string $why): string => "{$name}: {$why}",
                array_keys($result['failed']),
                $result['failed'],
            )));
        }

        return Response::structured([
            'message' => sprintf(
                '%d anúncio(s) mudados na Etsy, %d produto(s) preparados para quando forem enviados%s.',
                count($result['updated']),
                count($result['prepared']),
                $result['failed'] === [] ? '' : ', '.count($result['failed']).' recusado(s) pela Etsy',
            ),
            'shipping_profile_id' => $profileId,
            'updated_on_etsy' => $result['updated'],
            'prepared_here' => $result['prepared'],
            'refused_by_etsy' => $result['failed'],
        ]);
    }
}
