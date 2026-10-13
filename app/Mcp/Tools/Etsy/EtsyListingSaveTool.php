<?php

namespace App\Mcp\Tools\Etsy;

use App\Etsy\EtsyListingSync;
use App\Http\Requests\Etsy\SaveEtsyListingRequest;
use App\Mcp\FormRequestRunner;
use App\Mcp\PriceGuard;
use App\Mcp\Tools\WriteTool;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Support\Money;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('etsy_listing_save')]
#[Title('Acertar o anúncio de um produto para a Etsy')]
#[Description('Guarda NO 12STUDIO o que o anúncio de um produto diz de próprio na Etsy: título, descrição (texto simples), etiquetas (até 13, 20 caracteres cada), materiais, categoria ("taxonomy_id", ver etsy_taxonomy), atributos da categoria ("properties"), secção da loja, perfis de envio/processamento/devolução, o preço Etsy de cada variante ("variants": variant_id + etsy_price em euros, IVA incluído; "enabled": false para não a anunciar) e as fotografias que NÃO vão ("excluded_image_ids" — a lista completa das excluídas). Manda só o que muda; um valor vazio tira o conteúdo próprio e volta a valer o do produto. NÃO envia nada para a Etsy: isso é etsy_listing_push. Um preço suspeito — mais de 50% de diferença do site, ou abaixo do custo — é recusado até o utilizador confirmar e repetires com "confirm": true.')]
#[IsDestructive(false)]
#[IsIdempotent]
class EtsyListingSaveTool extends WriteTool
{
    public function __construct(
        private EtsyListingSync $sync,
        private FormRequestRunner $runner,
        private PriceGuard $guard,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        $id = fn (string $what) => $schema->integer()->min(1)->description($what);

        return [
            'product_id' => $schema->integer()->min(1)->required()->description('Id do produto no 12studio.'),
            'title' => $schema->string()->description('Título na Etsy (até 140 caracteres). Vazio = o nome do produto.'),
            'description' => $schema->string()->description('Descrição em texto simples. Vazio = a do produto, sem a marcação.'),
            'tags' => $schema->array()->items($schema->string())->description('Etiquetas: até 13, cada uma até 20 caracteres. [] = as do produto.'),
            'materials' => $schema->array()->items($schema->string())->description('Materiais. [] = os das variantes.'),
            'taxonomy_id' => $id('Categoria da Etsy (ver etsy_taxonomy). Prefere uma categoria final.'),
            'who_made' => $schema->string()->description('i_did, someone_else ou collective.'),
            'when_made' => $schema->string()->description('made_to_order ou um intervalo de anos (ex. 2020_2026).'),
            'shipping_profile_id' => $id('Perfil de envio (ver etsy_shipping_list).'),
            'return_policy_id' => $id('Política de devolução (ver etsy_shipping_list).'),
            'readiness_state_id' => $id('Perfil de processamento (ver etsy_shipping_list). Sem ele vale o predefinido para o modo do produto.'),
            'shop_section_id' => $id('Secção da loja (ver etsy_sections_list). Sem ela vale a da categoria do produto.'),
            'properties' => $schema->array()->items($schema->object([
                'property_id' => $schema->integer()->required(),
                'value_ids' => $schema->array()->items($schema->integer()),
                'values' => $schema->array()->items($schema->string()),
                'scale_id' => $schema->integer(),
            ]))->description('Atributos da categoria, como etsy_taxonomy os devolve. A lista completa: substitui a que lá estiver.'),
            'variants' => $schema->array()->items($schema->object([
                'variant_id' => $schema->integer()->required(),
                'etsy_price' => $schema->string()->description('Preço próprio na Etsy, em euros ("27,90"). Vazio = o do site (mais a margem global, se houver).'),
                'enabled' => $schema->boolean()->description('false para esta variante não ser anunciada na Etsy.'),
            ]))->description('Só as variantes que mudam.'),
            'excluded_image_ids' => $schema->array()->items($schema->integer())->description('Ids das fotografias do produto que não vão para a Etsy (lista completa; [] = vão todas).'),
            'confirm' => $schema->boolean()->description('Só depois de o utilizador confirmar um preço que a primeira resposta recusou.'),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $product = Product::query()->find((int) $request->get('product_id'));

        if ($product === null) {
            return Response::error('Produto não encontrado.');
        }

        $data = $this->runner->validate(SaveEtsyListingRequest::class, $request->all(), $user, ['product' => $product]);

        if (! $request->boolean('confirm')) {
            $concerns = $this->priceConcerns($data);

            if ($concerns !== []) {
                return Response::error(PriceGuard::message($concerns));
            }
        }

        $this->sync->saveContent($product, $data);

        $fields = array_values(array_filter(
            [...EtsyListingSync::CONTENT_FIELDS, 'variants', 'excluded_image_ids'],
            fn (string $field): bool => array_key_exists($field, $data),
        ));

        $this->changes = ['product_id' => $product->id, 'fields' => $fields];

        $payload = $this->sync->payload($product->fresh() ?? $product);

        return Response::structured([
            'message' => "Guardado no 12studio para \"{$product->name}\". Nada foi para a Etsy: envia com etsy_listing_push.",
            'saved' => $fields,
            'ready' => $payload->isReady(),
            'blockers' => $payload->blockers,
            'warnings' => $payload->warnings,
        ]);
    }

    /**
     * O mesmo travao dos precos das variantes, agora para o preco da Etsy:
     * compara com o preco do site, que e o que a variante vale hoje.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    private function priceConcerns(array $data): array
    {
        $concerns = [];

        foreach ((array) ($data['variants'] ?? []) as $row) {
            if (! is_array($row) || ! filled($row['etsy_price'] ?? null)) {
                continue;
            }

            $variant = Variant::query()->with(['product', 'material'])->find((int) $row['variant_id']);

            if ($variant === null) {
                continue;
            }

            foreach ($this->guard->concerns($variant, Money::fromDecimal((string) $row['etsy_price'])) as $concern) {
                $concerns[] = "{$variant->sku}: {$concern}";
            }
        }

        return $concerns;
    }
}
