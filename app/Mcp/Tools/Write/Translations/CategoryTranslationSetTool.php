<?php

namespace App\Mcp\Tools\Write\Translations;

use App\Mcp\Tools\WriteTool;
use App\Models\Category;
use App\Models\User;
use App\Services\TranslationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('category_translation_set')]
#[Title('Traduzir categoria')]
#[Description('Grava uma categoria noutro idioma da montra (es, en ou fr): nome e descrição. Manda só o que muda.')]
#[IsDestructive(false)]
#[IsIdempotent]
class CategoryTranslationSetTool extends WriteTool
{
    public function __construct(
        private TranslationService $translations,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required()->description('Id da categoria (ver categories_list).'),
            'locale' => $schema->string()->required()->description('es, en ou fr.'),
            'name' => $schema->string()->max(160),
            'description' => $schema->string()->max(2000),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $category = Category::query()->find((int) $request->get('id'));

        if ($category === null) {
            return Response::error('Categoria não encontrada.');
        }

        $locale = (string) $request->get('locale');
        $fields = Arr::only($request->all(), ['name', 'description']);

        if ($fields === []) {
            return Response::error('Não indicaste nenhum campo para traduzir.');
        }

        $translation = $this->translations->setCategory($category, $locale, $fields);
        $this->changes = ['category_id' => $category->id, 'locale' => $locale, 'fields' => array_keys($fields)];

        return Response::structured([
            'message' => 'Tradução gravada.',
            'translation' => ['category_id' => $category->id, 'locale' => $locale, 'name' => $translation->name],
        ]);
    }
}
