<?php

namespace App\Mcp\Tools\Write\Catalog;

use App\Http\Requests\Tag\StoreTagRequest;
use App\Mcp\FormRequestRunner;
use App\Mcp\Tools\WriteTool;
use App\Models\Tag;
use App\Models\User;
use App\Services\TagService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('tag_create')]
#[Title('Criar etiqueta')]
#[Description('Cria uma etiqueta de produtos ou de encomendas. Não é preciso criá-las antes de usar: product_create e product_update criam as etiquetas que ainda não existem.')]
#[IsDestructive(false)]
#[IsIdempotent]
class TagCreateTool extends WriteTool
{
    /** As de clientes nao se gerem pelo MCP na v1. */
    private const SCOPES = [Tag::SCOPE_PRODUCT, Tag::SCOPE_ORDER];

    public function __construct(
        private FormRequestRunner $runner,
        private TagService $tags,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->max(60)->required(),
            'scope' => $schema->string()->enum(self::SCOPES)->description('product (por omissão) ou order.'),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $scope = $request->get('scope') ?? Tag::SCOPE_PRODUCT;

        if (! in_array($scope, self::SCOPES, true)) {
            return Response::error('Só etiquetas de produtos ou de encomendas.');
        }

        $data = $this->runner->validate(StoreTagRequest::class, ['name' => $request->get('name'), 'scope' => $scope], $user);

        $tag = $this->tags->store($data);
        $this->changes = ['created' => ['tag_id' => $tag->id, 'scope' => $tag->scope]];

        return Response::structured([
            'message' => "Etiqueta \"{$tag->name}\" pronta.",
            'tag' => ['id' => $tag->id, 'name' => $tag->name, 'slug' => $tag->slug, 'scope' => $tag->scope],
        ]);
    }
}
