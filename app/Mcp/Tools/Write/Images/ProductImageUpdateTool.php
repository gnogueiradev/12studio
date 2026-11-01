<?php

namespace App\Mcp\Tools\Write\Images;

use App\Http\Requests\ProductImage\UpdateProductImageRequest;
use App\Mcp\FormRequestRunner;
use App\Mcp\Presenters\CatalogPresenter;
use App\Mcp\Tools\Write\Concerns\FindsProductImages;
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
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('product_image_update')]
#[Title('Editar fotografia')]
#[Description('Muda o texto alternativo de uma fotografia (o que ela mostra, para acessibilidade e SEO). O ficheiro em si não se edita: para trocar a imagem, carrega a nova com product_image_upload_url e apaga a antiga com product_image_delete.')]
#[IsDestructive(false)]
#[IsIdempotent]
class ProductImageUpdateTool extends WriteTool
{
    use FindsProductImages;

    public function __construct(
        private FormRequestRunner $runner,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'image_id' => $schema->integer()->required()->description('Id da fotografia (ver product_get).'),
            'alt' => $schema->string()->max(160)->description('Novo texto alternativo. Vazio ou ausente apaga-o.'),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $image = $this->findImage((int) $request->get('image_id'));

        if ($image instanceof Response) {
            return $image;
        }

        // As mesmas regras do backoffice.
        $data = $this->runner->validate(UpdateProductImageRequest::class, ['alt' => $request->get('alt')], $user, ['image' => $image]);

        $before = $image->alt;
        $image->update($data);

        $this->changes = [
            'product_id' => $image->product_id,
            'image_id' => $image->id,
            'before' => ['alt' => $before],
            'after' => ['alt' => $image->alt],
        ];

        return Response::structured([
            'message' => 'Fotografia atualizada.',
            'image' => CatalogPresenter::image($image),
        ]);
    }
}
