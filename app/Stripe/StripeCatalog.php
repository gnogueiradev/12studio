<?php

namespace App\Stripe;

use App\Models\Product;
use App\Models\Variant;
use Illuminate\Support\Str;

/**
 * O espelho do catalogo no Stripe: um Product por produto, um Price por
 * variante. Serve os relatorios do Stripe — o que se cobra numa encomenda e
 * sempre o preco da encomenda (ver StripeCheckout).
 *
 * O 12studio manda e o Stripe segue: nada do que la se mudar a mao volta para
 * ca, e a proxima sincronizacao escreve por cima.
 *
 * Um Price do Stripe nao se edita. Quando o preco de uma variante muda, cria-se
 * um novo e arquiva-se o antigo — as vendas antigas continuam ligadas ao preco
 * com que foram feitas.
 */
class StripeCatalog
{
    public function __construct(
        private StripeClient $stripe,
    ) {}

    /**
     * Um Product do Stripe so esta ativo enquanto o produto se pode vender: um
     * arquivado ou apagado fica la (as vendas apontam para ele), mas inativo.
     */
    public static function sellable(Product $product): bool
    {
        return ! $product->trashed() && $product->status !== 'archived';
    }

    public function sync(Product $product): void
    {
        $product->loadMissing(['variants.color', 'primaryImage', 'images']);

        $this->syncProduct($product);

        foreach ($product->variants as $variant) {
            $this->syncPrice($product, $variant);
        }
    }

    private function syncProduct(Product $product): void
    {
        $data = [
            'name' => Str::limit($product->name, 250, ''),
            'active' => self::sellable($product),
            'description' => $this->description($product),
            'metadata' => ['product_id' => (string) $product->getKey()],
        ];

        $image = $this->imageUrl($product);

        if ($image !== null) {
            $data['images'] = [$image];
        }

        if ($product->stripe_product_id !== null) {
            $this->stripe->post("products/{$product->stripe_product_id}", $data);

            return;
        }

        // Com chave de idempotencia: se a resposta se perder, a proxima
        // tentativa recebe o mesmo Product em vez de criar um segundo.
        $created = $this->stripe->post('products', $data, "12studio-product-{$product->getKey()}");

        $product->forceFill(['stripe_product_id' => (string) $created['id']])->saveQuietly();
    }

    private function syncPrice(Product $product, Variant $variant): void
    {
        $wanted = $variant->active && self::sellable($product);

        // O stripe_price_cents e tambem o sinal de que o Price esta ativo: ao
        // arquivar fica a null. Assim uma sincronizacao sem mudancas nao faz
        // pedido nenhum — um produto com quarenta variantes nao faz quarenta.
        if (! $wanted) {
            if ($variant->stripe_price_id !== null && $variant->stripe_price_cents !== null) {
                $this->archivePrice($variant->stripe_price_id);
                $variant->forceFill(['stripe_price_cents' => null])->saveQuietly();
            }

            return;
        }

        if ($variant->stripe_price_id !== null && $variant->stripe_price_cents === $variant->price_cents) {
            return;
        }

        $created = $this->stripe->post('prices', [
            'product' => $product->stripe_product_id,
            'currency' => config('stripe.currency', 'eur'),
            'unit_amount' => $variant->price_cents,
            'nickname' => $this->nickname($variant),
            'metadata' => ['variant_id' => (string) $variant->getKey(), 'sku' => $variant->sku],
        ], "12studio-price-{$variant->getKey()}-{$variant->price_cents}-".($variant->stripe_price_id ?? 'novo'));

        // Um Price que volta (variante reativada) nasce de novo: o antigo ja
        // esta arquivado.
        if ($variant->stripe_price_id !== null && $variant->stripe_price_cents !== null) {
            $this->archivePrice($variant->stripe_price_id);
        }

        $variant->forceFill([
            'stripe_price_id' => (string) $created['id'],
            'stripe_price_cents' => $variant->price_cents,
        ])->saveQuietly();
    }

    private function archivePrice(string $priceId): void
    {
        $this->stripe->post("prices/{$priceId}", ['active' => false]);
    }

    /** O que distingue a variante nas listas do Dashboard: "M · Azul", ou o SKU. */
    private function nickname(Variant $variant): string
    {
        $parts = array_filter([$variant->size_label, $variant->color?->name]);

        return $parts === [] ? $variant->sku : implode(' · ', $parts);
    }

    /** A descricao e HTML (o editor do backoffice); o Stripe quer texto. */
    private function description(Product $product): ?string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string) $product->description))));

        return $text === '' ? null : Str::limit($text, 1000);
    }

    /**
     * O Stripe vai buscar a imagem ao URL: so serve um endereco publico. Em
     * desenvolvimento (http://localhost) fica sem imagem.
     */
    private function imageUrl(Product $product): ?string
    {
        $image = $product->primaryImage ?? $product->images->first();
        $url = $image?->url;

        return is_string($url) && str_starts_with($url, 'https://') ? $url : null;
    }
}
