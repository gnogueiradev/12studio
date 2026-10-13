<?php

namespace App\Mcp\Tools\Etsy;

use App\Etsy\EtsyOrderImporter;
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

#[Name('etsy_orders_import')]
#[Title('Importar já as encomendas da Etsy')]
#[Description('Traz agora as encomendas novas da Etsy, sem esperar pela importação automática (que corre de 5 em 5 minutos). Cada venda paga torna-se uma encomenda do 12studio (canal "etsy"), desce o stock e entra no quadro de produção; as que já cá estão acompanham o que aconteceu lá (cancelada, reembolsada, enviada). Correr duas vezes não duplica nada. Não escreve nada na Etsy. A resposta diz quantas entraram e quantas ficaram por rever (uma linha que não casou com nenhuma variante, outra moeda) — essas vêem-se com orders_list (sales_channel "etsy") e order_get.')]
#[IsDestructive(false)]
#[IsIdempotent]
#[IsOpenWorld]
class EtsyOrdersImportTool extends WriteTool
{
    use TalksToEtsy;

    public function __construct(
        private EtsyOrderImporter $importer,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function etsy(Request $request, User $user): Response|ResponseFactory
    {
        $result = $this->importer->run();

        $this->changes = $result;

        return Response::structured([
            'message' => match (true) {
                $result['fetched'] === 0 => 'Não há nada de novo na Etsy desde a última importação.',
                default => sprintf(
                    '%d encomenda(s) nova(s), %d atualizada(s), %d por rever, %d falhada(s).',
                    $result['imported'],
                    $result['updated'],
                    $result['review'],
                    $result['failed'],
                ),
            },
            'receipts_seen' => $result['fetched'],
            'imported' => $result['imported'],
            'updated' => $result['updated'],
            'needs_review' => $result['review'],
            'failed' => $result['failed'],
        ]);
    }
}
