<?php

namespace App\Mcp\Tools\Write\Translations;

use App\Mcp\Tools\WriteTool;
use App\Models\Page;
use App\Models\User;
use App\Services\TranslationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('page_content_translate')]
#[Title('Traduzir uma página')]
#[Description('Grava o texto de um idioma (es, en ou fr) nos blocos de uma página da montra, pelo id de cada bloco (ver page_get). Fica no rascunho: o site só muda quando o dono publicar no editor. Os campos que não mandares ficam como estão.')]
#[IsDestructive(false)]
#[IsIdempotent]
class PageContentTranslateTool extends WriteTool
{
    public function __construct(
        private TranslationService $translations,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required()->description('Id da página (ver pages_list).'),
            'locale' => $schema->string()->required()->description('es, en ou fr.'),
            'blocks' => $schema->array()->required()->description('Lista de {id, content}: o id do bloco e um objeto campo => texto traduzido (as mesmas chaves de page_get).'),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $page = Page::query()->find((int) $request->get('id'));

        if ($page === null) {
            return Response::error('Página não encontrada.');
        }

        $blocks = $request->get('blocks');

        if (! is_array($blocks) || $blocks === []) {
            return Response::error('Manda pelo menos um bloco.');
        }

        $locale = (string) $request->get('locale');
        $page = $this->translations->translatePage($page, $locale, array_values($blocks));
        $this->changes = ['page_id' => $page->id, 'locale' => $locale, 'blocks' => count($blocks)];

        return Response::structured([
            'message' => 'Tradução gravada no rascunho. O site muda quando a página for publicada no editor (Montra → Páginas).',
            'page' => [
                'id' => $page->id,
                'name' => $page->name,
                'unpublished_changes' => $page->hasUnpublishedChanges(),
                'missing_translations' => $this->translations->pageMissingLocales($page),
            ],
        ]);
    }
}
