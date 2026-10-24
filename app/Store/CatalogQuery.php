<?php

namespace App\Store;

use App\Models\Category;
use App\Models\Color;
use App\Models\Material;
use App\Models\Product;
use App\Models\Variant;
use App\Support\SearchText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * O catalogo da montra: os filtros do URL aplicados aos produtos visiveis.
 *
 * Os parametros sao em portugues e simples de partilhar:
 *   ?q=vaso&cor=3,5&material=2&preco_min=10&preco_max=40&disponibilidade=pronto&ordem=preco-asc
 * Valores que nao se percebem sao ignorados (nunca dao erro): um link antigo
 * ou mexido a mao mostra o catalogo, nao uma pagina partida.
 */
class CatalogQuery
{
    public const SORTS = ['destaques', 'novidades', 'preco-asc', 'preco-desc'];

    public const AVAILABILITY = ['pronto', 'encomenda'];

    public const PER_PAGE = 24;

    /**
     * @param  array<int, int>  $colors
     * @param  array<int, int>  $materials
     */
    public function __construct(
        public readonly ?Category $category = null,
        public readonly string $search = '',
        public readonly array $colors = [],
        public readonly array $materials = [],
        public readonly ?int $minPrice = null,
        public readonly ?int $maxPrice = null,
        public readonly ?string $availability = null,
        public readonly string $sort = 'destaques',
    ) {}

    public static function fromRequest(Request $request, ?Category $category = null): self
    {
        $ids = fn (string $key): array => array_values(array_unique(array_filter(
            array_map('intval', explode(',', (string) $request->query($key, ''))),
            fn (int $id): bool => $id > 0,
        )));

        // Os precos vem em euros inteiros e guardam-se em centimos.
        $price = function (string $key) use ($request): ?int {
            $value = $request->query($key);

            return is_numeric($value) && (float) $value >= 0 ? (int) round((float) $value * 100) : null;
        };

        $availability = $request->query('disponibilidade');
        $sort = $request->query('ordem');

        return new self(
            category: $category,
            search: mb_substr(trim((string) $request->query('q', '')), 0, 100),
            colors: $ids('cor'),
            materials: $ids('material'),
            minPrice: $price('preco_min'),
            maxPrice: $price('preco_max'),
            availability: in_array($availability, self::AVAILABILITY, true) ? $availability : null,
            sort: in_array($sort, self::SORTS, true) ? $sort : 'destaques',
        );
    }

    /**
     * @return Builder<Product>
     */
    public function query(): Builder
    {
        $query = ProductCard::query(Product::query());

        if ($this->category !== null) {
            $query->where('category_id', $this->category->id);
        }

        foreach (SearchText::terms($this->search) as $term) {
            $query->whereRaw("search_text LIKE ? ESCAPE '\\'", [SearchText::like($term)]);
        }

        $variant = function (callable $constraint) use ($query): void {
            $query->whereHas('variants', fn (Builder $variant) => $constraint($variant->visibleInStore()));
        };

        if ($this->colors !== []) {
            $variant(fn (Builder $variant) => $variant->whereIn('color_id', $this->colors));
        }

        if ($this->materials !== []) {
            $variant(fn (Builder $variant) => $variant->whereIn('material_id', $this->materials));
        }

        if ($this->minPrice !== null || $this->maxPrice !== null) {
            $variant(fn (Builder $variant) => $variant
                ->when($this->minPrice !== null, fn (Builder $q) => $q->where('price_cents', '>=', $this->minPrice))
                ->when($this->maxPrice !== null, fn (Builder $q) => $q->where('price_cents', '<=', $this->maxPrice)));
        }

        if ($this->availability === 'pronto') {
            $query->where('fulfillment_mode', 'in_stock');
            $variant(fn (Builder $variant) => $variant->whereRaw('stock - reserved_stock > 0'));
        } elseif ($this->availability === 'encomenda') {
            $query->where(fn (Builder $q) => $q
                ->whereIn('fulfillment_mode', ['made_to_order', 'custom'])
                ->orWhere('allow_backorder', true));
        }

        return $this->sorted($query);
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    private function sorted(Builder $query): Builder
    {
        if (str_starts_with($this->sort, 'preco')) {
            $query->addSelect(['products.*', 'store_price' => Variant::query()
                ->selectRaw('min(price_cents)')
                ->whereColumn('variants.product_id', 'products.id')
                ->visibleInStore(),
            ])->orderBy('store_price', $this->sort === 'preco-asc' ? 'asc' : 'desc');
        } elseif ($this->sort === 'novidades') {
            $query->latest();
        } else {
            $query->orderByDesc('featured')->orderBy('sort_order')->latest();
        }

        // Desempate estavel: sem isto, duas paginas podiam repetir ou saltar
        // produtos com o mesmo preco.
        return $query->orderBy('products.id');
    }

    /**
     * As opcoes dos filtros, do catalogo inteiro (ou da categoria): todas as
     * cores e materiais que ha a venda, e o intervalo de precos.
     *
     * @return array<string, mixed>
     */
    public function facets(): array
    {
        $variants = Variant::query()
            ->visibleInStore()
            ->whereIn('product_id', Product::query()
                ->visibleInStore()
                ->when($this->category, fn (Builder $q) => $q->where('category_id', $this->category->id))
                ->select('id'));

        $colorIds = (clone $variants)->whereNotNull('color_id')->distinct()->pluck('color_id');
        $materialIds = (clone $variants)->whereNotNull('material_id')->distinct()->pluck('material_id');

        $categories = Category::query()
            ->where('status', 'visible')
            ->whereHas('products', fn (Builder $q) => $q->visibleInStore())
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        return [
            'categories' => $categories->map(fn (Category $category): array => [
                'name' => $category->name,
                'slug' => $category->slug,
            ])->all(),
            'colors' => Color::query()->whereKey($colorIds)->orderBy('sort_order')->orderBy('name')->get()
                ->map(fn (Color $color): array => ['id' => $color->id, 'name' => $color->name, 'hex' => $color->hex_color])
                ->all(),
            'materials' => Material::query()->whereKey($materialIds)->orderBy('sort_order')->orderBy('name')->get()
                ->map(fn (Material $material): array => ['id' => $material->id, 'name' => $material->name])
                ->all(),
            'price' => [
                'min' => (int) floor(((int) (clone $variants)->min('price_cents')) / 100),
                'max' => (int) ceil(((int) (clone $variants)->max('price_cents')) / 100),
            ],
        ];
    }

    /**
     * Os filtros em vigor, como o frontend os mostra e volta a enviar.
     *
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return [
            'q' => $this->search,
            'cor' => $this->colors,
            'material' => $this->materials,
            'preco_min' => $this->minPrice === null ? null : intdiv($this->minPrice, 100),
            'preco_max' => $this->maxPrice === null ? null : intdiv($this->maxPrice, 100),
            'disponibilidade' => $this->availability,
            'ordem' => $this->sort,
        ];
    }

    public function isFiltered(): bool
    {
        return $this->search !== '' || $this->colors !== [] || $this->materials !== []
            || $this->minPrice !== null || $this->maxPrice !== null || $this->availability !== null;
    }
}
