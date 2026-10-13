<?php

namespace App\Mcp\Tools\Etsy;

use App\Etsy\EtsyShipping;
use App\Http\Requests\Etsy\SaveProcessingProfileRequest;
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

#[Name('etsy_processing_profile_save')]
#[Title('Criar ou editar perfil de processamento')]
#[Description('Um perfil de processamento da Etsy: quanto tempo leva uma peça a sair, conforme esteja pronta na prateleira ("ready_to_ship") ou seja feita por encomenda ("made_to_order"), com o tempo mínimo e máximo em dias ou semanas. Com "definition_id" edita, sem ele cria. Editar um perfil em uso por anúncios à venda muda a data de envio que os compradores veem: a primeira chamada só avisa, e é preciso "confirm": true. Que perfil é a predefinição de cada tipo escolhe-se no backoffice (Etsy → Envios).')]
#[IsDestructive(false)]
#[IsOpenWorld]
class EtsyProcessingProfileSaveTool extends WriteTool
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
            'definition_id' => $schema->integer()->min(1)->description('Id do perfil a editar (ver etsy_shipping_list). Sem isto, cria um novo.'),
            'readiness_state' => $schema->string()->enum(['ready_to_ship', 'made_to_order'])->required()->description('ready_to_ship (pronto a enviar) ou made_to_order (feito por encomenda).'),
            'min_processing_time' => $schema->integer()->min(1)->required()->description('Tempo mínimo até sair.'),
            'max_processing_time' => $schema->integer()->min(1)->required()->description('Tempo máximo até sair.'),
            'processing_time_unit' => $schema->string()->enum(['days', 'weeks'])->required()->description('days ou weeks.'),
            'confirm' => $schema->boolean()->description('Só depois de o utilizador confirmar o aviso da primeira resposta.'),
        ];
    }

    protected function etsy(Request $request, User $user): Response|ResponseFactory
    {
        $data = $this->runner->validate(SaveProcessingProfileRequest::class, $request->all(), $user);
        $definitionId = filled($data['definition_id'] ?? null) ? (int) $data['definition_id'] : null;

        // Um perfil novo ainda nao e de ninguem; so editar pode mexer no que
        // os compradores estao a ver.
        $active = $definitionId === null ? 0 : $this->shipping->activeListingsUsingProcessing($definitionId);

        if ($active > 0 && ! $request->boolean('confirm')) {
            return $this->askFirst(sprintf(
                'O perfil de processamento %d é usado por %s: mudar-lhe o prazo (%d a %d %s) muda já a data de envio que os compradores veem.',
                $definitionId,
                $this->activeListings($active),
                $data['min_processing_time'],
                $data['max_processing_time'],
                $data['processing_time_unit'] === 'weeks' ? 'semanas' : 'dias',
            ));
        }

        $savedId = $this->shipping->saveProcessingProfile($data);

        $this->changes = [
            'definition_id' => $savedId,
            'created' => $definitionId === null,
            'active_listings' => $active,
        ];

        return Response::structured([
            'message' => $definitionId === null
                ? 'Perfil de processamento criado na Etsy.'
                : 'Perfil de processamento atualizado na Etsy.',
            'definition_id' => $savedId,
            'active_listings_affected' => $active,
        ]);
    }
}
