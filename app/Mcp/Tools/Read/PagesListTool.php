<?php

namespace App\Mcp\Tools\Read;

use App\Mcp\Tools\StudioTool;
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
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('pages_list')]
#[Title('Listar páginas da montra')]
#[Description('As páginas do site público montadas por blocos: as de sistema (início, sobre, contacto, legais, modelos do catálogo e do produto) e as landing pages. Diz o estado e em que idiomas falta tradução.')]
#[IsReadOnly]
#[IsIdempotent]
class PagesListTool extends StudioTool
{
    public function __construct(
        private TranslationService $translations,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        return Response::structured([
            'pages' => Page::query()->orderBy('type')->orderBy('id')->get()->map(fn (Page $page): array => [
                'id' => $page->id,
                'type' => $page->type,
                'key' => $page->key,
                'name' => $page->name,
                'path' => $page->path(),
                'status' => $page->status,
                'unpublished_changes' => $page->hasUnpublishedChanges(),
                'blocks' => count($page->draft_blocks),
                'missing_translations' => $this->translations->pageMissingLocales($page),
            ])->all(),
        ]);
    }
}
