<?php

namespace App\Mcp\Tools\Etsy;

use App\Etsy\EtsyShipping;
use App\Http\Requests\Etsy\SaveShippingProfileRequest;
use App\Mcp\FormRequestRunner;
use App\Mcp\Tools\Etsy\Concerns\TalksToEtsy;
use App\Mcp\Tools\WriteTool;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[Name('etsy_shipping_profile_save')]
#[Title('Criar ou editar perfil de envio')]
#[Description('Cria um perfil de envio na Etsy ou edita um que já existe. CRIAR (sem "shipping_profile_id"): leva o título, o país de origem e o PRIMEIRO destino — custo, custo por artigo adicional, o país ou a região, e o prazo de entrega em dias. EDITAR (com "shipping_profile_id"): só muda o título, o país de origem e o código postal; na Etsy os custos vivem nos destinos, e mudam-se com etsy_shipping_destination_save. Mais destinos acrescentam-se com essa mesma ferramenta.')]
#[IsDestructive(false)]
#[IsOpenWorld]
class EtsyShippingProfileSaveTool extends WriteTool
{
    use TalksToEtsy;

    public function __construct(
        private EtsyShipping $shipping,
        private FormRequestRunner $runner,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'shipping_profile_id' => $schema->integer()->min(1)->description('Id do perfil a editar. Sem isto, cria um novo.'),
            'title' => $schema->string()->max(255)->required()->description('Nome do perfil (só o vês tu, na gestão da loja).'),
            'origin_country_iso' => $schema->string()->required()->description('País de onde sai a encomenda, em duas letras (PT).'),
            'origin_postal_code' => $schema->string()->description('Código postal de origem.'),
            'primary_cost' => $schema->string()->description('Só ao criar: custo de envio do primeiro destino, em euros ("4,50").'),
            'secondary_cost' => $schema->string()->description('Só ao criar: custo por cada artigo adicional ("0" se não houver).'),
            'destination_country_iso' => $schema->string()->description('Só ao criar: país do primeiro destino (PT, ES…). Em alternativa a destination_region.'),
            'destination_region' => $schema->string()->enum(['eu', 'non_eu', 'none'])->description('Só ao criar: região do primeiro destino — eu, non_eu, ou none (todo o lado).'),
            'min_delivery_days' => $schema->integer()->min(1)->max(45)->description('Só ao criar: dias mínimos de entrega.'),
            'max_delivery_days' => $schema->integer()->min(1)->max(45)->description('Só ao criar: dias máximos de entrega.'),
            'shipping_carrier_id' => $schema->integer()->description('Só ao criar, em alternativa aos dias: transportadora (ver etsy_shipping_list).'),
            'mail_class' => $schema->string()->description('Só ao criar, com a transportadora: a classe de correio dela.'),
        ];
    }

    protected function etsy(Request $request, User $user): Response|ResponseFactory
    {
        $data = $this->runner->validate(SaveShippingProfileRequest::class, $request->all(), $user);
        $creating = ! filled($data['shipping_profile_id'] ?? null);

        $profileId = $this->shipping->saveProfile($data);

        $this->changes = [
            'shipping_profile_id' => $profileId,
            'created' => $creating,
            'title' => $data['title'],
        ];

        return Response::structured([
            'message' => $creating
                ? "Perfil de envio \"{$data['title']}\" criado na Etsy."
                : "Perfil de envio {$profileId} atualizado na Etsy (título e origem).",
            'shipping_profile_id' => $profileId,
        ]);
    }
}
