<?php

namespace App\Mcp\Tools\Etsy;

use App\Etsy\EtsyShop;
use App\Http\Requests\Etsy\SaveReturnPolicyRequest;
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

#[Name('etsy_return_policy_save')]
#[Title('Criar ou editar política de devolução')]
#[Description('Uma política de devolução da loja na Etsy: se aceita devoluções, se aceita trocas e, aceitando alguma, o prazo em dias (7, 14, 21, 30, 45, 60 ou 90). Com "policy_id" edita, sem ele cria. Não é obrigatória para lojas da UE. Editar uma política em uso por anúncios à venda muda já o que os compradores leem: a primeira chamada só avisa, e é preciso "confirm": true.')]
#[IsDestructive(false)]
#[IsOpenWorld]
class EtsyReturnPolicySaveTool extends WriteTool
{
    use AsksBeforeEtsy;
    use TalksToEtsy;

    public function __construct(
        private EtsyShop $shop,
        private FormRequestRunner $runner,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'policy_id' => $schema->integer()->min(1)->description('Id da política a editar (ver etsy_shipping_list). Sem isto, cria uma nova.'),
            'accepts_returns' => $schema->boolean()->required()->description('Se aceita devoluções.'),
            'accepts_exchanges' => $schema->boolean()->required()->description('Se aceita trocas.'),
            'return_deadline' => $schema->integer()->description('Prazo em dias: '.implode(', ', SaveReturnPolicyRequest::DEADLINES).'. Obrigatório se aceitar devoluções ou trocas.'),
            'confirm' => $schema->boolean()->description('Só depois de o utilizador confirmar o aviso da primeira resposta.'),
        ];
    }

    protected function etsy(Request $request, User $user): Response|ResponseFactory
    {
        $data = $this->runner->validate(SaveReturnPolicyRequest::class, $request->all(), $user);
        $policyId = filled($data['policy_id'] ?? null) ? (int) $data['policy_id'] : null;

        $active = $policyId === null ? 0 : $this->shop->activeListingsUsingReturnPolicy($policyId);

        if ($active > 0 && ! $request->boolean('confirm')) {
            return $this->askFirst(sprintf(
                'A política de devolução %d é usada por %s: mudá-la altera já o que os compradores leem nesses anúncios.',
                $policyId,
                $this->activeListings($active),
            ));
        }

        $savedId = $this->shop->saveReturnPolicy($data);

        $this->changes = [
            'policy_id' => $savedId,
            'created' => $policyId === null,
            'active_listings' => $active,
        ];

        return Response::structured([
            'message' => $policyId === null
                ? 'Política de devolução criada na Etsy.'
                : 'Política de devolução atualizada na Etsy.',
            'policy_id' => $savedId,
            'active_listings_affected' => $active,
        ]);
    }
}
