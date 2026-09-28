<?php

namespace App\Mcp\Tools\Read;

use App\Mcp\Tools\StudioTool;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('tags_list')]
#[Title('Listar etiquetas')]
#[Description('Etiquetas de produtos ou de encomendas, com quantos itens usam cada uma. As etiquetas de clientes não estão disponíveis por aqui.')]
#[IsReadOnly]
#[IsIdempotent]
class TagsListTool extends StudioTool
{
    /**
     * As de clientes ficam de fora: dizem coisas sobre pessoas ("VIP",
     * "nao paga") e o MCP nao expoe clientes na v1.
     */
    private const SCOPES = [Tag::SCOPE_PRODUCT, Tag::SCOPE_ORDER];

    public function schema(JsonSchema $schema): array
    {
        return [
            'scope' => $schema->string()->enum(self::SCOPES)->description('product (por omissão) ou order.'),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $data = $request->validate(['scope' => ['nullable', 'in:'.implode(',', self::SCOPES)]]);
        $scope = $data['scope'] ?? Tag::SCOPE_PRODUCT;
        $relation = $scope === Tag::SCOPE_ORDER ? 'orders' : 'products';

        $tags = Tag::query()
            ->inScope($scope)
            ->withCount($relation)
            ->orderBy('name')
            ->get();

        return Response::structured([
            'scope' => $scope,
            'tags' => $tags->map(fn (Tag $tag): array => [
                'id' => $tag->id,
                'name' => $tag->name,
                'slug' => $tag->slug,
                'uses' => (int) $tag->getAttribute("{$relation}_count"),
            ])->all(),
        ]);
    }
}
