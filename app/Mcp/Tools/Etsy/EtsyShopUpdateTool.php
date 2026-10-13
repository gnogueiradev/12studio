<?php

namespace App\Mcp\Tools\Etsy;

use App\Etsy\EtsyShop;
use App\Http\Requests\Etsy\UpdateEtsyShopRequest;
use App\Mcp\FormRequestRunner;
use App\Mcp\Tools\Etsy\Concerns\AsksBeforeEtsy;
use App\Mcp\Tools\Etsy\Concerns\TalksToEtsy;
use App\Mcp\Tools\WriteTool;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[Name('etsy_shop_update')]
#[Title('Mudar os textos da loja na Etsy')]
#[Description('Muda os textos públicos da loja na Etsy: o título (até 55 caracteres), o anúncio no topo da loja, a mensagem que o comprador recebe depois de comprar, a mensagem das compras digitais e a política adicional. Manda só o que muda; um texto vazio ("") apaga-o lá. É a montra — fica à vista de toda a gente — e por isso pede sempre confirmação: a primeira chamada mostra o que lá está e o que passaria a estar, e só com "confirm": true é que muda.')]
#[IsDestructive(false)]
#[IsIdempotent]
#[IsOpenWorld]
class EtsyShopUpdateTool extends WriteTool
{
    use AsksBeforeEtsy;
    use TalksToEtsy;

    public function __construct(
        private EtsyShop $shop,
        private FormRequestRunner $runner,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->max(55)->description('Título da loja (a linha por baixo do nome).'),
            'announcement' => $schema->string()->max(5000)->description('O anúncio no topo da loja.'),
            'sale_message' => $schema->string()->max(5000)->description('Mensagem que o comprador recebe depois de comprar.'),
            'digital_sale_message' => $schema->string()->max(5000)->description('O mesmo, para compras digitais.'),
            'policy_additional' => $schema->string()->max(5000)->description('Política adicional da loja.'),
            'confirm' => $schema->boolean()->description('Só depois de o utilizador confirmar o que a primeira resposta mostrou.'),
        ];
    }

    protected function etsy(Request $request, User $user): Response|ResponseFactory
    {
        $data = $this->runner->validate(
            UpdateEtsyShopRequest::class,
            Arr::only($request->all(), EtsyShop::TEXT_FIELDS),
            $user,
        );

        if (! $request->boolean('confirm')) {
            $current = $this->shop->texts();
            $lines = [];

            foreach ($data as $field => $value) {
                $lines[] = sprintf(
                    '%s: "%s" → "%s"',
                    $field,
                    Str::limit((string) ($current[$field] ?? ''), 300),
                    Str::limit((string) ($value ?? ''), 300),
                );
            }

            return $this->askFirst('Vais mudar os textos públicos da loja na Etsy. '.implode(' | ', $lines).'.');
        }

        $changed = $this->shop->updateTexts($data);

        $this->changes = [
            'before' => $changed['before'],
            'after' => $changed['after'],
        ];

        return Response::structured([
            'message' => 'Textos da loja atualizados na Etsy.',
            'changed' => array_keys($changed['after']),
        ]);
    }
}
