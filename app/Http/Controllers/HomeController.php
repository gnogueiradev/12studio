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
     * Com a loja fechada (config/access.php) nem chega a haver query — '/'
     * devolve a landing "em breve" e mais nada.
     */
    public function __invoke(): Response
    {
        if (! config('access.store_open')) {
            return Inertia::render('coming-soon');
        }

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
                'imageUrl' => $product->primaryImage?->url,
                'fulfillmentMode' => $product->fulfillment_mode,
            ]);

        return Inertia::render('home', [
            'products' => $products,
        ]);
    }
}
