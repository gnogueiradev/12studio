<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    /**
     * Homepage da montra: produtos ativos, destacados primeiro.
     *
     * Com a loja fechada nem chega aqui: o middleware `store.open` devolve a
     * landing "em breve" a quem nao e admin.
     */
    public function __invoke(): Response
    {
        $products = Product::query()
            ->where('status', 'active')
            ->with(['primaryImage', 'defaultVariant', 'category'])
            ->orderByDesc('featured')
            ->orderByDesc('created_at')
            ->limit(12)
            ->get()
            ->map(fn (Product $product): array => [
                'id' => $product->id,
                'name' => $product->name,
                'slug' => $product->slug,
                'category' => $product->category?->name,
                'priceCents' => $product->defaultVariant?->price_cents,
                'image' => $product->primaryImage?->responsive($product->name),
                'fulfillmentMode' => $product->fulfillment_mode,
            ]);

        return Inertia::render('home', [
            'products' => $products,
        ]);
    }
}
