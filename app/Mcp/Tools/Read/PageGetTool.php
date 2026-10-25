<?php

namespace App\Mcp\Tools\Read;

use App\Mcp\Tools\StudioTool;
use App\Models\Page;
use App\Models\User;
use App\PageBuilder\BlockRegistry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('page_get')]
#[Title('Ver o texto de uma página')]
#[Description('O texto de cada bloco de uma página da montra (o rascunho), por idioma: o que existe em pt, es, en e fr. Usa o id de cada bloco e as chaves dos campos para traduzir com page_content_translate.')]
#[IsReadOnly]
#[IsIdempotent]
class PageGetTool extends StudioTool
{
    public function __construct(
        private BlockRegistry $registry,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required()->description('Id da página (ver pages_list).'),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $page = Page::query()->find((int) $request->get('id'));

        if ($page === null) {
            return Response::error('Página não encontrada.');
        }

        $blocks = [];

        foreach ($page->draft_blocks as $block) {
            $definition = $this->registry->get((string) ($block['type'] ?? ''));

            if ($definition === null) {
                continue;
            }

            $fields = [];

            foreach ($definition->fields() as $field) {
                if (! $field->translatable) {
                    continue;
                }

                $fields[] = [
                    'key' => $field->key,
                    'type' => $field->type,
                    'label' => $field->label,
                    'subfields' => $field->type === 'repeater' ? array_map(fn ($sub): string => $sub->key, $field->fields) : null,
                    'values' => array_map(
                        fn (array $content): mixed => $content[$field->key] ?? null,
                        array_filter((array) ($block['content'] ?? []), 'is_array'),
                    ),
                ];
            }

            $blocks[] = [
                'id' => $block['id'] ?? null,
                'type' => $definition->type(),
                'label' => $definition->label(),
                'visible' => $block['visible'] ?? true,
                'fields' => $fields,
            ];
        }

        return Response::structured([
            'page' => ['id' => $page->id, 'name' => $page->name, 'path' => $page->path(), 'status' => $page->status],
            'blocks' => $blocks,
            'note' => 'Os links (campos do tipo link) são {label, href}: traduz o label e mantém o href. Os rich text são HTML simples.',
        ]);
    }
}
