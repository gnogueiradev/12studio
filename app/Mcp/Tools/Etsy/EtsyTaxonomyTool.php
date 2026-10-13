<?php

namespace App\Mcp\Tools\Etsy;

use App\Etsy\EtsyInsights;
use App\Mcp\Tools\Etsy\Concerns\TalksToEtsy;
use App\Mcp\Tools\StudioTool;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('etsy_taxonomy')]
#[Title('Categorias da Etsy')]
#[Description('As categorias da Etsy, de duas maneiras. Com "query": procura categorias pelo nome (em inglês, ex. "vase", "planter") e devolve o id e o caminho de cada uma — é o "taxonomy_id" de um anúncio. Com "taxonomy_id": as propriedades dessa categoria (cor, material, dimensões…), com os valores possíveis e quais podem ser variações. A árvore tem milhares de categorias, por isso não se lista inteira.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld]
class EtsyTaxonomyTool extends StudioTool
{
    use TalksToEtsy;

    public function __construct(
        private EtsyInsights $insights,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->max(80)->description('Texto a procurar no nome ou no caminho da categoria, em inglês.'),
            'taxonomy_id' => $schema->integer()->min(1)->description('Id de uma categoria, para ver as propriedades dela.'),
        ];
    }

    protected function etsy(Request $request, User $user): Response|ResponseFactory
    {
        $data = $request->validate([
            'query' => ['nullable', 'string', 'max:80', 'required_without:taxonomy_id'],
            'taxonomy_id' => ['nullable', 'integer', 'min:1'],
        ], [
            'query.required_without' => 'Indica "query" (para procurar categorias) ou "taxonomy_id" (para ver as propriedades de uma).',
        ]);

        if (isset($data['taxonomy_id'])) {
            return Response::structured([
                'taxonomy_id' => (int) $data['taxonomy_id'],
                'properties' => $this->insights->taxonomyProperties((int) $data['taxonomy_id']),
            ]);
        }

        $categories = $this->insights->searchTaxonomy((string) $data['query']);

        return Response::structured([
            'query' => (string) $data['query'],
            'categories' => $categories,
            'note' => $categories === []
                ? 'Nenhuma categoria com esse texto. Os nomes estão em inglês — tenta um termo mais geral.'
                : 'Para um anúncio, prefere uma categoria final ("is_leaf": true).',
        ]);
    }
}
