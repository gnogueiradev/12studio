<?php

namespace App\Mcp\Tools\Write\Images;

use App\Mcp\Presenters\CatalogPresenter;
use App\Mcp\Tools\Write\Concerns\HandlesProductImages;
use App\Mcp\Tools\WriteTool;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\ImageService;
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

#[Name('product_image_update')]
#[Title('Editar fotografia')]
#[Description('Altera uma fotografia: texto alternativo, se é a principal, a posição na galeria (a contar de 1) e a variante a que pertence ("variant_id": null desassocia). Manda só o que muda. Para mudar a principal, marca a que a deve passar a ser; o ficheiro não se substitui aqui (apaga e volta a adicionar).')]
#[IsDestructive(false)]
#[IsIdempotent]
class ProductImageUpdateTool extends WriteTool
{
    use HandlesProductImages;

    private const FIELDS = ['alt', 'is_primary', 'position', 'variant_id'];

    public function __construct(
        private ImageService $images,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'image_id' => $schema->integer()->required()->description('Id da fotografia (ver product_get).'),
            'alt' => $schema->string()->max(150)->description('Texto alternativo.'),
            'is_primary' => $schema->boolean()->description('true passa-a a principal e desmarca a anterior.'),
            'position' => $schema->integer()->min(1)->description('Posição na galeria, a contar de 1.'),
            'variant_id' => $schema->integer()->nullable()->description('Variante deste produto, ou null para desassociar.'),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $data = $request->validate([
            'image_id' => ['required', 'integer'],
            'alt' => ['nullable', 'string', 'max:150'],
            'is_primary' => ['boolean'],
            'position' => ['integer', 'min:1'],
            'variant_id' => ['nullable', 'integer'],
        ]);

        $image = ProductImage::query()->find((int) $data['image_id']);

        if ($image === null) {
            return Response::error('Fotografia não encontrada.');
        }

        $product = $this->ownerOf($image);

        if ($refusal = $this->refusalIfDeleted($product)) {
            return $refusal;
        }

        $changes = Arr::only($data, self::FIELDS);

        if ($changes === []) {
            return Response::error('Não indicaste nenhum campo para mudar.');
        }

        $context = ['product_id' => $product->id, 'image_id' => $image->id, 'variant_id' => $changes['variant_id'] ?? $image->variant_id];

        if (isset($changes['variant_id']) && ! $this->ownsVariant($product, (int) $changes['variant_id'])) {
            return $this->refuse('variante_invalida', 'Essa variante não é deste produto.', $context);
        }

        if (array_key_exists('is_primary', $changes)) {
            $changes['is_primary'] = (bool) $changes['is_primary'];

            // Um produto com fotos nunca fica sem principal.
            if ($image->is_primary && ! $changes['is_primary']) {
                return $this->refuse('sem_principal', 'Para mudar a fotografia principal, marca a que a deve passar a ser.', $context);
            }
        }

        $before = Arr::only(CatalogPresenter::image($image), self::FIELDS);

        return $this->logged($context, function () use ($product, $image, $changes, $context, $before): ResponseFactory {
            $this->images->change($product, $image, $changes);

            $image->refresh();
            $this->logDone($context);

            $this->changes = [
                'product_id' => $product->id,
                'image_id' => $image->id,
                'before' => $before,
                'after' => Arr::only(CatalogPresenter::image($image), self::FIELDS),
            ];

            return Response::structured([
                'message' => 'Fotografia atualizada.',
                'image' => CatalogPresenter::image($image),
                'images' => $this->gallery($product),
            ]);
        });
    }
}
