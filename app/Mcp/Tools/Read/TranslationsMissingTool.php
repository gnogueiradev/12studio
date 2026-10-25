<?php

namespace App\Mcp\Tools\Read;

use App\Mcp\Tools\StudioTool;
use App\Models\User;
use App\Services\TranslationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('translations_missing')]
#[Title('O que falta traduzir')]
#[Description('Produtos à venda, categorias e páginas da montra sem tradução (missing) ou com tradução feita sobre um português que entretanto mudou (outdated), por idioma.')]
#[IsReadOnly]
#[IsIdempotent]
class TranslationsMissingTool extends StudioTool
{
    public function __construct(
        private TranslationService $translations,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->enum(['all', 'products', 'categories', 'pages'])->description('Por omissão, tudo.'),
            'locale' => $schema->string()->enum(['es', 'en', 'fr'])->description('Só um idioma; por omissão, os ativos na montra.'),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $type = (string) ($request->get('type') ?? 'all');
        $locale = $request->get('locale');

        $rows = $this->translations->missing($type, is_string($locale) ? $locale : null);

        return Response::structured([
            'total' => count($rows),
            'items' => $rows,
        ]);
    }
}
