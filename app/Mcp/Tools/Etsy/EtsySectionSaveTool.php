<?php

namespace App\Mcp\Tools\Etsy;

use App\Etsy\EtsyShop;
use App\Http\Requests\Etsy\SaveShopSectionRequest;
use App\Mcp\FormRequestRunner;
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

#[Name('etsy_section_save')]
#[Title('Criar ou renomear secção da loja')]
#[Description('Cria uma secção na loja da Etsy ou, com "section_id", muda-lhe o nome. Uma secção é só um título (até 24 caracteres) onde os anúncios se arrumam. Para apagar uma, etsy_delete com kind "section".')]
#[IsDestructive(false)]
#[IsOpenWorld]
class EtsySectionSaveTool extends WriteTool
{
    use TalksToEtsy;

    public function __construct(
        private EtsyShop $shop,
        private FormRequestRunner $runner,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'section_id' => $schema->integer()->min(1)->description('Id da secção a renomear (ver etsy_sections_list). Sem isto, cria uma nova.'),
            'title' => $schema->string()->max(24)->required()->description('Título da secção, até 24 caracteres.'),
        ];
    }

    protected function etsy(Request $request, User $user): Response|ResponseFactory
    {
        $data = $this->runner->validate(SaveShopSectionRequest::class, $request->all(), $user);
        $sectionId = filled($data['section_id'] ?? null) ? (int) $data['section_id'] : null;

        $savedId = $this->shop->saveSection((string) $data['title'], $sectionId);

        $this->changes = [
            'section_id' => $savedId,
            'created' => $sectionId === null,
            'title' => $data['title'],
        ];

        return Response::structured([
            'message' => $sectionId === null
                ? "Secção \"{$data['title']}\" criada na Etsy."
                : "Secção {$savedId} renomeada para \"{$data['title']}\".",
            'section_id' => $savedId,
        ]);
    }
}
