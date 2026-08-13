<?php

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\Color;
use App\Models\Material;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProductCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Material $material;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        // A matriz precisa de cor E material: sao os dois eixos que definem uma
        // peca imprimivel.
        $this->material = Material::factory()->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(): array
    {
        return [
            'name' => 'Caixa Âmbar',
            'category_id' => null,
            'description' => 'Caixa impressa em PLA.',
            'status' => 'active',
            'featured' => true,
            'vat_rate' => 23,
            'fulfillment_mode' => 'made_to_order',
            'production_time_days' => 3,
            'allow_backorder' => false,
            'max_open_production_qty' => 10,
        ];
    }

    /**
     * A matriz do modal, ja com os dois precos que ela exige — os testes que
     * nao falam de precos passam so os eixos e nao repetem o molde.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function matrix(array $overrides = []): array
    {
        return [
            'color_ids' => [],
            'material_ids' => [],
            'sizes' => [],
            'normal_price' => '29.00',
            'wholesale_price' => '21.00',
            ...$overrides,
        ];
    }

    public function test_index_lists_products(): void
    {
        Product::query()->create([
            'name' => 'Vaso Espiral',
            'slug' => 'vaso-espiral',
            'status' => 'active',
            'fulfillment_mode' => 'in_stock',
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.produtos.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/produtos/index')
                ->has('products.data', 1)
                ->where('products.data.0.name', 'Vaso Espiral'));
    }

    public function test_store_creates_product_with_ascii_slug(): void
    {
        $category = Category::query()->create(['name' => 'Decoração', 'slug' => 'decoracao']);

        $this->actingAs($this->admin)
            ->post(route('admin.produtos.store'), [
                ...$this->validPayload(),
                'category_id' => $category->id,
            ])
            ->assertRedirect(route('admin.produtos.index'));

        $this->assertDatabaseHas('products', [
            'name' => 'Caixa Âmbar',
            'slug' => 'caixa-ambar',
            'category_id' => $category->id,
            'fulfillment_mode' => 'made_to_order',
            'max_open_production_qty' => 10,
        ]);
    }

    public function test_store_generates_a_variant_per_colour_material_and_size(): void
    {
        $colors = Color::factory()->count(3)->create();
        $petg = Material::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('admin.produtos.store'), [
                ...$this->validPayload(),
                'name' => 'Vaso ondulado',
                'variants' => $this->matrix([
                    'color_ids' => $colors->modelKeys(),
                    'material_ids' => [$this->material->id, $petg->id],
                    'sizes' => ['Pequeno', 'Grande'],
                ]),
            ])
            ->assertRedirect(route('admin.produtos.index'));

        $product = Product::query()->where('slug', 'vaso-ondulado')->sole();

        // 3 cores x 2 materiais x 2 tamanhos, com o molde (os precos) aplicado
        // a todas — o que difere entre variantes edita-se depois, uma a uma.
        $this->assertSame(12, $product->variants()->count());
        $this->assertSame(12, $product->variants()->where([
            'price_cents' => 2900,
            'wholesale_price_cents' => 2100,
        ])->count());

        // A gramagem e o tempo nao vem do modal: sao dados de producao e
        // nascem por preencher, na ficha de cada variante.
        $this->assertSame(12, $product->variants()
            ->whereNull('filament_weight_grams')
            ->whereNull('printing_time_minutes')
            ->count());

        // O stock entra sempre a zero: a primeira contagem tem de passar pelo
        // StockService para ficar registada como movimento.
        $this->assertSame(0, (int) $product->variants()->sum('stock'));
    }

    /**
     * Cada variante gerada aponta para os DOIS eixos. Sem o material, o custo
     * de producao dela ficava sem preco/kg de onde sair.
     */
    public function test_the_generated_variants_carry_the_material(): void
    {
        $color = Color::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('admin.produtos.store'), [
                ...$this->validPayload(),
                'name' => 'Vaso ondulado',
                'variants' => $this->matrix([
                    'color_ids' => [$color->id],
                    'material_ids' => [$this->material->id],
                ]),
            ]);

        $this->assertDatabaseHas('variants', [
            'color_id' => $color->id,
            'material_id' => $this->material->id,
        ]);
    }

    /**
     * Cor e material sao os dois eixos que definem uma peca imprimivel — que
     * tom, e em que filamento. Sem um deles nao ha matriz nenhuma.
     */
    public function test_a_matrix_without_materials_creates_no_variants(): void
    {
        $color = Color::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('admin.produtos.store'), [
                ...$this->validPayload(),
                'name' => 'Vaso ondulado',
                'variants' => $this->matrix([
                    'color_ids' => [$color->id],
                    'sizes' => ['Pequeno'],
                ]),
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('variants', 0);
    }

    public function test_a_matrix_without_colours_creates_no_variants(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.produtos.store'), [
                ...$this->validPayload(),
                'name' => 'Vaso ondulado',
                'variants' => $this->matrix([
                    'material_ids' => [$this->material->id],
                    'sizes' => ['Pequeno'],
                ]),
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('variants', 0);
    }

    /** Sem tamanhos, o produto cartesiano degenera num par cor x material. */
    public function test_a_matrix_without_sizes_generates_one_variant_per_pair(): void
    {
        $colors = Color::factory()->count(2)->create();
        $petg = Material::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('admin.produtos.store'), [
                ...$this->validPayload(),
                'name' => 'Vaso ondulado',
                'variants' => $this->matrix([
                    'color_ids' => $colors->modelKeys(),
                    'material_ids' => [$this->material->id, $petg->id],
                ]),
            ]);

        $product = Product::query()->where('slug', 'vaso-ondulado')->sole();

        $this->assertSame(4, $product->variants()->count());
        $this->assertSame(0, $product->variants()->whereNotNull('size_label')->count());
    }

    public function test_the_matrix_prices_accept_a_decimal_comma(): void
    {
        $color = Color::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('admin.produtos.store'), [
                ...$this->validPayload(),
                'name' => 'Vaso ondulado',
                // Colados de outro lado com virgula, como o admin escreve. Os
                // tres campos, e nao so o do meio: a normalizacao corre sobre
                // o molde inteiro.
                'variants' => $this->matrix([
                    'color_ids' => [$color->id],
                    'material_ids' => [$this->material->id],
                    'normal_price' => '29,50',
                    'wholesale_price' => '21,25',
                    'sale_price' => '24,90',
                ]),
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('variants', [
            'price_cents' => 2490,
            'compare_at_cents' => 2950,
            'wholesale_price_cents' => 2125,
        ]);
    }

    /**
     * Em promocao os dois precos trocam de lugar: `price_cents` passa a ser o
     * efectivo e `compare_at_cents` o riscado. E o VariantService quem o faz —
     * o molde limita-se a mandar os dois.
     */
    public function test_a_matrix_with_a_sale_price_swaps_the_two_columns(): void
    {
        $colors = Color::factory()->count(2)->create();

        $this->actingAs($this->admin)
            ->post(route('admin.produtos.store'), [
                ...$this->validPayload(),
                'name' => 'Vaso ondulado',
                'variants' => $this->matrix([
                    'color_ids' => $colors->modelKeys(),
                    'material_ids' => [$this->material->id],
                    'sale_price' => '24.90',
                ]),
            ])
            ->assertSessionHasNoErrors();

        $product = Product::query()->where('slug', 'vaso-ondulado')->sole();

        $this->assertSame(2, $product->variants()->where([
            'price_cents' => 2490,
            'compare_at_cents' => 2900,
        ])->count());
    }

    /** Sem promocao nao ha nada para riscar na montra. */
    public function test_a_matrix_without_a_sale_price_leaves_nothing_struck_through(): void
    {
        $color = Color::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('admin.produtos.store'), [
                ...$this->validPayload(),
                'name' => 'Vaso ondulado',
                'variants' => $this->matrix([
                    'color_ids' => [$color->id],
                    'material_ids' => [$this->material->id],
                ]),
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('variants', [
            'price_cents' => 2900,
            'compare_at_cents' => null,
        ]);
    }

    public function test_store_numbers_the_generated_references_in_sequence(): void
    {
        $colors = Color::factory()->count(2)->create();

        $this->actingAs($this->admin)
            ->post(route('admin.produtos.store'), [
                ...$this->validPayload(),
                'name' => 'Vaso ondulado',
                'variants' => $this->matrix([
                    'color_ids' => $colors->modelKeys(),
                    'material_ids' => [$this->material->id],
                ]),
            ]);

        $product = Product::query()->where('slug', 'vaso-ondulado')->sole();

        $this->assertSame(
            ['VASO-ONDULADO-1', 'VASO-ONDULADO-2'],
            $product->variants()->orderBy('id')->pluck('sku')->all(),
        );

        // Sem tamanhos ha uma variante por cor, e nenhuma leva rotulo.
        $this->assertSame(0, $product->variants()->whereNotNull('size_label')->count());
    }

    public function test_the_first_generated_variant_is_the_default_one(): void
    {
        $colors = Color::factory()->count(3)->create();

        $this->actingAs($this->admin)
            ->post(route('admin.produtos.store'), [
                ...$this->validPayload(),
                'name' => 'Vaso ondulado',
                'variants' => $this->matrix([
                    'color_ids' => $colors->modelKeys(),
                    'material_ids' => [$this->material->id],
                ]),
            ]);

        $product = Product::query()->where('slug', 'vaso-ondulado')->sole();

        // Uma e uma so: duas defaults tornavam indeterminado o preco da montra.
        $this->assertSame(1, $product->variants()->where('is_default', true)->count());
        $this->assertSame(
            $product->variants()->orderBy('id')->value('id'),
            $product->variants()->where('is_default', true)->value('id'),
        );
    }

    public function test_store_without_a_matrix_creates_no_variants(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.produtos.store'), [
                ...$this->validPayload(),
                'name' => 'Vaso ondulado',
                'status' => 'draft',
            ])
            ->assertSessionHasNoErrors();

        // O rascunho guardado sem cores nenhumas — o preco so e exigido quando
        // ha matriz para o gastar.
        $this->assertSame(0, Product::query()->where('slug', 'vaso-ondulado')->sole()->variants()->count());
    }

    public function test_a_matrix_without_a_price_is_rejected(): void
    {
        $color = Color::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('admin.produtos.store'), [
                ...$this->validPayload(),
                'variants' => $this->matrix([
                    'color_ids' => [$color->id],
                    'material_ids' => [$this->material->id],
                    'normal_price' => '',
                ]),
            ])
            ->assertSessionHasErrors('variants.normal_price');

        // Sem esta regra as variantes nasciam todas a zero euros e vendaveis.
        $this->assertDatabaseCount('variants', 0);
    }

    /**
     * Obrigatorio ao contrario da ficha da variante, onde e opcional: um
     * produto que nasce sem preco de revenda chega as encomendas manuais sem
     * nada que se lhe aplique, e ninguem volta atras para o preencher.
     */
    public function test_a_matrix_without_a_wholesale_price_is_rejected(): void
    {
        $color = Color::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('admin.produtos.store'), [
                ...$this->validPayload(),
                'variants' => $this->matrix([
                    'color_ids' => [$color->id],
                    'material_ids' => [$this->material->id],
                    'wholesale_price' => '',
                ]),
            ])
            ->assertSessionHasErrors('variants.wholesale_price');

        $this->assertDatabaseCount('variants', 0);
    }

    /**
     * Uma "promocao" igual ou acima do preco normal riscaria na montra um valor
     * mais baixo do que o pedido — anuncio de aumento em vez de desconto. A
     * mesma regra da ficha da variante, para o molde nao gerar variantes que
     * ela recusaria.
     */
    public function test_a_matrix_sale_price_above_the_normal_one_is_rejected(): void
    {
        $color = Color::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('admin.produtos.store'), [
                ...$this->validPayload(),
                'variants' => $this->matrix([
                    'color_ids' => [$color->id],
                    'material_ids' => [$this->material->id],
                    'sale_price' => '29.00',
                ]),
            ])
            ->assertSessionHasErrors('variants.sale_price');

        $this->assertDatabaseCount('variants', 0);
    }

    /** Acima do que o cliente final paga nao e revenda. */
    public function test_a_matrix_wholesale_price_above_the_sale_price_is_rejected(): void
    {
        $color = Color::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('admin.produtos.store'), [
                ...$this->validPayload(),
                // 21,00 cabe debaixo dos 29,00 normais, mas nao dos 19,90 que
                // o cliente passa a pagar em promocao.
                'variants' => $this->matrix([
                    'color_ids' => [$color->id],
                    'material_ids' => [$this->material->id],
                    'sale_price' => '19.90',
                ]),
            ])
            ->assertSessionHasErrors('variants.wholesale_price');

        $this->assertDatabaseCount('variants', 0);
    }

    public function test_update_ignores_a_matrix_smuggled_into_the_payload(): void
    {
        $product = Product::factory()->create();
        $color = Color::factory()->create();

        $this->actingAs($this->admin)->patch(route('admin.produtos.update', $product), [
            ...$this->validPayload(),
            'variants' => $this->matrix([
                'color_ids' => [$color->id],
                'material_ids' => [$this->material->id],
            ]),
        ]);

        // A matriz so existe na criacao: senao cada correccao ao nome do
        // produto criava variantes novas por baixo.
        $this->assertSame(0, $product->variants()->count());
    }

    /**
     * O modal so manda `images` a criar — mas a defesa esta no
     * UpdateProductRequest, nao no browser.
     */
    public function test_update_ignores_images_smuggled_into_the_payload(): void
    {
        Storage::fake('public');

        $product = Product::factory()->create();

        $this->actingAs($this->admin)->patch(route('admin.produtos.update', $product), [
            ...$this->validPayload(),
            'images' => [UploadedFile::fake()->image('a.jpg', 800, 800)],
        ]);

        // Senao cada correccao ao nome do produto acumulava mais uma copia da
        // mesma foto na galeria.
        $this->assertSame(0, $product->images()->count());
        Storage::disk('public')->assertDirectoryEmpty('products');
    }

    /**
     * As fotografias viajam no mesmo pedido que cria o produto: nao ha para
     * onde as enviar antes disso — o ImageService precisa de um Product, e o
     * indice parcial product_images_one_primary_per_product exige que a
     * primeira de um produto seja a principal.
     */
    public function test_store_saves_the_photos_that_came_with_the_product(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin)
            ->post(route('admin.produtos.store'), [
                ...$this->validPayload(),
                'images' => [
                    UploadedFile::fake()->image('frente.jpg', 800, 800),
                    UploadedFile::fake()->image('verso.jpg', 800, 800),
                ],
            ])
            ->assertRedirect(route('admin.produtos.index'));

        $product = Product::query()->where('name', 'Caixa Âmbar')->firstOrFail();
        $images = $product->images()->orderBy('sort_order')->get();

        $this->assertCount(2, $images);
        $this->assertTrue($images[0]->is_primary);
        $this->assertFalse($images[1]->is_primary);
        $this->assertSame([1, 2], $images->pluck('sort_order')->all());

        foreach ($images as $image) {
            Storage::disk('public')->assertExists($image->path);
        }
    }

    /**
     * O caminho sem fotografias e o normal, e continua a ser um pedido JSON —
     * o Inertia so passa a multipart quando ha mesmo ficheiros.
     */
    public function test_store_creates_the_product_without_any_photo(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin)
            ->post(route('admin.produtos.store'), $this->validPayload())
            ->assertRedirect(route('admin.produtos.index'));

        $this->assertDatabaseCount('product_images', 0);
    }

    public function test_store_rejects_a_file_that_is_not_an_image(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin)
            ->post(route('admin.produtos.store'), [
                ...$this->validPayload(),
                'images' => [UploadedFile::fake()->create('malware.php', 10, 'application/x-php')],
            ])
            ->assertSessionHasErrors('images.0');

        // O produto tambem nao nasce: a validacao corre antes do servico.
        $this->assertDatabaseCount('products', 0);
    }

    /**
     * A rota de edicao foi-se com a pagina: o produto edita-se no modal da
     * listagem, como os materiais e as impressoras.
     */
    public function test_the_old_edit_page_is_gone(): void
    {
        $product = Product::factory()->create();

        $this->actingAs($this->admin)
            ->get("/admin/produtos/{$product->id}/edit")
            ->assertNotFound();
    }

    public function test_store_rejects_invalid_fulfillment_mode_and_status(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.produtos.store'), [
                ...$this->validPayload(),
                'status' => 'inexistente',
                'fulfillment_mode' => 'teleporte',
            ])
            ->assertSessionHasErrors(['status', 'fulfillment_mode']);
    }

    public function test_update_edits_product(): void
    {
        $product = Product::query()->create([
            'name' => 'Vaso Espiral',
            'slug' => 'vaso-espiral',
            'status' => 'draft',
            'fulfillment_mode' => 'in_stock',
        ]);

        $this->actingAs($this->admin)->patch(route('admin.produtos.update', $product), [
            ...$this->validPayload(),
            'name' => 'Vaso Espiral XL',
        ]);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'name' => 'Vaso Espiral XL',
            'slug' => 'vaso-espiral-xl',
            'status' => 'active',
        ]);
    }

    public function test_destroy_archives_instead_of_deleting(): void
    {
        $product = Product::query()->create([
            'name' => 'Vaso Espiral',
            'slug' => 'vaso-espiral',
            'status' => 'active',
            'fulfillment_mode' => 'in_stock',
        ]);

        // Volta para tras e nao para o indice: arquiva-se a partir da linha da
        // listagem, e um redirect fixo perdia a pagina e os filtros activos.
        $this->actingAs($this->admin)
            ->from(route('admin.produtos.index', ['status' => 'active']))
            ->delete(route('admin.produtos.destroy', $product))
            ->assertRedirect(route('admin.produtos.index', ['status' => 'active']));

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'status' => 'archived',
        ]);
    }

    public function test_restore_returns_an_archived_product_to_draft(): void
    {
        $product = Product::query()->create([
            'name' => 'Vaso Espiral',
            'slug' => 'vaso-espiral',
            'status' => 'archived',
            'fulfillment_mode' => 'in_stock',
        ]);

        $this->actingAs($this->admin)
            ->from(route('admin.produtos.index'))
            ->patch(route('admin.produtos.restaurar', $product))
            ->assertRedirect(route('admin.produtos.index'));

        // Rascunho e nao activo: restaurar corrige o arquivo, nao republica na
        // montra sem ninguem ter pedido.
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'status' => 'draft',
        ]);
    }

    public function test_non_admins_cannot_restore_a_product(): void
    {
        $product = Product::query()->create([
            'name' => 'Vaso Espiral',
            'slug' => 'vaso-espiral',
            'status' => 'archived',
            'fulfillment_mode' => 'in_stock',
        ]);

        $this->actingAs(User::factory()->create())
            ->patch(route('admin.produtos.restaurar', $product))
            ->assertForbidden();
    }

    public function test_homepage_shows_only_active_products(): void
    {
        // A montra so aparece com a loja aberta; fechada, '/' e a landing.
        config(['access.store_open' => true]);

        Product::query()->create([
            'name' => 'Ativo', 'slug' => 'ativo',
            'status' => 'active', 'fulfillment_mode' => 'in_stock',
        ]);
        Product::query()->create([
            'name' => 'Rascunho', 'slug' => 'rascunho',
            'status' => 'draft', 'fulfillment_mode' => 'in_stock',
        ]);

        $this->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('home')
                ->has('products', 1)
                ->where('products.0.name', 'Ativo'));
    }

    public function test_non_admins_cannot_touch_products(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('admin.produtos.index'))
            ->assertForbidden();

        $this->actingAs($user)
            ->post(route('admin.produtos.store'), $this->validPayload())
            ->assertForbidden();

        $this->assertDatabaseMissing('products', ['name' => 'Caixa Âmbar']);
    }
}
