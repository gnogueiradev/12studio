<?php

namespace App\Mcp\Tools\Write\Images;

use App\Images\IncomingImage;
use App\Images\RemoteImageFetcher;
use App\Mcp\Tools\Write\Concerns\HandlesProductImages;
use App\Mcp\Tools\WriteTool;
use App\Models\Product;
use App\Models\User;
use App\Services\ImageService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('product_image_add')]
#[Title('Adicionar fotografia')]
#[Description('Adiciona uma fotografia a um produto, a partir de image_url (https público) OU de image_base64 — um e só um. Para fotos a sério usa o URL; sem URL, envia-as às fatias com product_image_upload_start/_chunk/_finish (o image_base64 tem de seguir inteiro numa só chamada e só serve para imagens pequenas, até umas dezenas de KB). Aceita JPG, PNG e WEBP, com pelo menos 600 px no lado menor. A primeira fotografia de um produto fica logo como principal. As versões reduzidas para a loja são feitas a seguir, em segundo plano. Não envia nada para a Etsy (isso é o etsy_listing_push).')]
#[IsDestructive(false)]
class ProductImageAddTool extends WriteTool
{
    use HandlesProductImages;

    public function __construct(
        private ImageService $images,
        private RemoteImageFetcher $fetcher,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'product_id' => $schema->integer()->required()->description('Id do produto.'),
            'image_url' => $schema->string()->description('Endereço https público da imagem. Em alternativa a image_base64.'),
            'image_base64' => $schema->string()->description('A imagem em base64, com ou sem o prefixo data:image/...;base64, . Em alternativa a image_url. Só para imagens pequenas: as maiores vão às fatias (product_image_upload_start).'),
            'filename' => $schema->string()->max(255)->description('Nome original, só para o registo: no disco o nome é gerado.'),
            'alt' => $schema->string()->max(150)->description('Texto alternativo. Sem ele, usa-se o nome do produto.'),
            'variant_id' => $schema->integer()->description('Variante deste produto a que a fotografia pertence. Na loja, só aparece com essa variante escolhida.'),
            'is_primary' => $schema->boolean()->description('Passa a ser a principal. Por omissão, só a primeira do produto o é.'),
            'position' => $schema->integer()->min(1)->description('Posição na galeria, a contar de 1. Por omissão, no fim.'),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'image_url' => ['nullable', 'string'],
            'image_base64' => ['nullable', 'string'],
            'filename' => ['nullable', 'string', 'max:255'],
            'alt' => ['nullable', 'string', 'max:150'],
            'variant_id' => ['nullable', 'integer'],
            'is_primary' => ['nullable', 'boolean'],
            'position' => ['nullable', 'integer', 'min:1'],
        ]);

        $url = (string) ($data['image_url'] ?? '');
        $base64 = (string) ($data['image_base64'] ?? '');

        $context = [
            'product_id' => (int) $data['product_id'],
            'variant_id' => $data['variant_id'] ?? null,
            'source' => $url !== '' ? 'url' : 'base64',
            // Sem caminho nem query string: e la que vivem os tokens.
            'host' => $url !== '' ? (parse_url($url, PHP_URL_HOST) ?: null) : null,
            'filename' => isset($data['filename']) ? Str::limit(basename((string) $data['filename']), 100, '') : null,
        ];

        if (($url === '') === ($base64 === '')) {
            return $this->refuse('origem_invalida', 'Indica um e só um de image_url ou image_base64.', $context);
        }

        $product = Product::withTrashed()->find($context['product_id']);

        if ($product === null) {
            return Response::error('Produto não encontrado.');
        }

        return $this->logged($context, fn (): ResponseFactory => $this->addIncoming(
            $this->images,
            $product,
            fn (): IncomingImage => $url !== ''
                ? IncomingImage::fromFile($this->fetcher->fetch($url))
                : IncomingImage::fromBase64($base64),
            $data,
            $context,
        ));
    }
}
