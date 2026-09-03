<?php

namespace Tests\Feature\Admin;

use App\Models\Material;
use App\Models\PrinterProfile;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * A aba "Producao" do produto: o tempo de impressao e a gramagem sao do
 * PRODUTO — todas as variantes gastam o mesmo — e os precos calculados a
 * partir deles vao para todas.
 *
 * Os numeros sao os da peca de referencia do PricingCalculatorTest: 50 g a
 * 17,00 EUR/kg, 3 h na Bambu Lab A1 -> 4,00 EUR de revenda, 7,00 EUR ao cliente.
 */
class VariantProductionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Product $product;

    private Material $material;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        PrinterProfile::factory()->isDefault()->create([
            'average_power_watts' => 145,
            'purchase_price_cents' => 40_000,
            'lifetime_hours' => 4_000,
            'maintenance_micros_per_hour' => 40_000,
        ]);
        $this->material = Material::factory()->create(['price_per_kg_cents' => 1_700]);
        $this->product = Product::factory()->create();
    }

    /**
     * Um par de valores, todas as variantes: a cor e o material mudam entre
     * elas, o tempo de maquina e o plastico gasto nao.
     */
    public function test_it_saves_the_same_print_time_and_weight_on_every_variant(): void
    {
        $white = Variant::factory()->for($this->product)->create();
        $black = Variant::factory()->for($this->product)->create(['printing_time_minutes' => 10]);
        $archived = Variant::factory()->for($this->product)->create(['active' => false]);

        $this->actingAs($this->admin)
            ->from(route('admin.produtos.index', ['editar' => $this->product->id]))
            ->patch(route('admin.produtos.variantes.producao', $this->product), [
                'hours' => 1,
                'minutes' => 30,
                'weight_grams' => 40,
            ])
            ->assertRedirect(route('admin.produtos.index', ['editar' => $this->product->id]));

        foreach ([$white, $black, $archived] as $variant) {
            $this->assertSame(90, $variant->refresh()->printing_time_minutes);
            $this->assertSame(40, $variant->filament_weight_grams);
        }
    }

    public function test_it_never_touches_the_variants_of_another_product(): void
    {
        $foreign = Variant::factory()->create(['printing_time_minutes' => 10, 'filament_weight_grams' => 5]);
        Variant::factory()->for($this->product)->create();

        $this->actingAs($this->admin)
            ->patch(route('admin.produtos.variantes.producao', $this->product), [
                'hours' => 2,
                'minutes' => 0,
                'weight_grams' => 50,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(10, $foreign->refresh()->printing_time_minutes);
        $this->assertSame(5, $foreign->filament_weight_grams);
    }

    /**
     * Um campo vazio apaga o valor — e diferente de zero, que e uma peca que
     * nao existe. So assim se limpa um engano.
     */
    public function test_an_empty_field_clears_the_value_everywhere(): void
    {
        $variant = Variant::factory()->for($this->product)->create([
            'printing_time_minutes' => 90,
            'filament_weight_grams' => 40,
        ]);

        $this->actingAs($this->admin)
            ->patch(route('admin.produtos.variantes.producao', $this->product), [
                'hours' => null,
                'minutes' => null,
                'weight_grams' => null,
            ])
            ->assertSessionHasNoErrors();

        $this->assertNull($variant->refresh()->printing_time_minutes);
        $this->assertNull($variant->filament_weight_grams);
    }

    /**
     * "0 h 45 min" e "2 h" sao ambos tempos: um dos dois campos preenchido
     * chega para haver tempo.
     */
    public function test_hours_alone_or_minutes_alone_still_count_as_a_time(): void
    {
        $variant = Variant::factory()->for($this->product)->create();

        $this->actingAs($this->admin)
            ->patch(route('admin.produtos.variantes.producao', $this->product), [
                'hours' => null,
                'minutes' => 45,
                'weight_grams' => 10,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(45, $variant->refresh()->printing_time_minutes);
    }

    public function test_it_validates_like_the_calculator_does(): void
    {
        Variant::factory()->for($this->product)->create();

        $this->actingAs($this->admin)
            ->patch(route('admin.produtos.variantes.producao', $this->product), [
                'hours' => 1,
                'minutes' => 75,
                'weight_grams' => -5,
            ])
            ->assertSessionHasErrors(['minutes', 'weight_grams']);
    }

    /**
     * O mesmo tempo e a mesma gramagem dao precos DIFERENTES quando o
     * material muda: e o preco/kg de cada filamento que entra na conta.
     * PVP e revenda vao os dois para cada variante.
     */
    public function test_applying_prices_writes_retail_and_wholesale_on_every_calculable_variant(): void
    {
        $pricier = Material::factory()->create(['price_per_kg_cents' => 3_400]);

        $reference = Variant::factory()->for($this->product)->create([
            'material_id' => $this->material->id,
            'filament_weight_grams' => 50,
            'printing_time_minutes' => 180,
            'price_cents' => 1_000,
            'wholesale_price_cents' => null,
        ]);
        $premium = Variant::factory()->for($this->product)->create([
            'material_id' => $pricier->id,
            'filament_weight_grams' => 50,
            'printing_time_minutes' => 180,
            'price_cents' => 1_000,
            'wholesale_price_cents' => null,
        ]);
        $noMaterial = Variant::factory()->for($this->product)->create([
            'material_id' => null,
            'filament_weight_grams' => 50,
            'printing_time_minutes' => 180,
            'price_cents' => 1_000,
        ]);

        $this->actingAs($this->admin)
            ->from(route('admin.produtos.index', ['editar' => $this->product->id]))
            ->post(route('admin.produtos.variantes.precos', $this->product))
            ->assertRedirect(route('admin.produtos.index', ['editar' => $this->product->id]));

        $this->assertSame(700, $reference->refresh()->price_cents);
        $this->assertSame(400, $reference->wholesale_price_cents);
        $this->assertNull($reference->compare_at_cents);

        $premium->refresh();
        $this->assertGreaterThan(700, $premium->price_cents);
        $this->assertGreaterThan(400, $premium->wholesale_price_cents);

        // Sem material o plastico seria de graca: fica como estava.
        $this->assertSame(1_000, $noMaterial->refresh()->price_cents);
    }

    public function test_applying_prices_skips_a_product_without_time_or_weight(): void
    {
        $variant = Variant::factory()->for($this->product)->create([
            'material_id' => $this->material->id,
            'filament_weight_grams' => 50,
            'printing_time_minutes' => null,
            'price_cents' => 1_000,
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.produtos.variantes.precos', $this->product))
            ->assertRedirect();

        $this->assertSame(1_000, $variant->refresh()->price_cents);
    }

    /**
     * "Todas" inclui as arquivadas: uma variante que volte a montra com o
     * preco antigo era uma armadilha, e escrever-lhe o preco novo nao custa
     * nada enquanto esta fora dela.
     */
    public function test_applying_prices_reaches_archived_variants_too(): void
    {
        $archived = Variant::factory()->for($this->product)->create([
            'material_id' => $this->material->id,
            'filament_weight_grams' => 50,
            'printing_time_minutes' => 180,
            'price_cents' => 1_000,
            'active' => false,
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.produtos.variantes.precos', $this->product))
            ->assertRedirect();

        $this->assertSame(700, $archived->refresh()->price_cents);
        $this->assertSame(400, $archived->wholesale_price_cents);
    }

    /**
     * Uma promocao abaixo do novo preco normal continua a fazer sentido e
     * fica; o cliente continua a pagar o promocional.
     */
    public function test_applying_prices_keeps_a_sale_that_is_still_below_the_new_price(): void
    {
        $variant = Variant::factory()->for($this->product)->create([
            'material_id' => $this->material->id,
            'filament_weight_grams' => 50,
            'printing_time_minutes' => 180,
            'price_cents' => 500,
            'compare_at_cents' => 1_000,
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.produtos.variantes.precos', $this->product));

        $variant->refresh();
        $this->assertSame(500, $variant->price_cents);
        $this->assertSame(700, $variant->compare_at_cents);
    }

    /**
     * Uma promocao igual ou acima do novo preco normal era um anuncio de
     * aumento, nao um desconto — a mesma regra do StoreVariantRequest. Cai.
     */
    public function test_applying_prices_drops_a_sale_that_would_no_longer_be_a_discount(): void
    {
        $variant = Variant::factory()->for($this->product)->create([
            'material_id' => $this->material->id,
            'filament_weight_grams' => 50,
            'printing_time_minutes' => 180,
            'price_cents' => 800,
            'compare_at_cents' => 1_000,
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.produtos.variantes.precos', $this->product));

        $variant->refresh();
        $this->assertSame(700, $variant->price_cents);
        $this->assertNull($variant->compare_at_cents);
    }

    public function test_applying_prices_uses_the_variant_own_printer(): void
    {
        // Uma maquina dez vezes mais cara de amortizar: 4000/4000 = 1,00 EUR/h
        // em vez de 0,10. Se o preco subir, foi a impressora da variante que
        // entrou na conta, e nao a predefinida.
        $expensive = PrinterProfile::factory()->create([
            'average_power_watts' => 145,
            'purchase_price_cents' => 400_000,
            'lifetime_hours' => 4_000,
            'maintenance_micros_per_hour' => 40_000,
        ]);
        $variant = Variant::factory()->for($this->product)->create([
            'material_id' => $this->material->id,
            'filament_weight_grams' => 50,
            'printing_time_minutes' => 180,
            'printer_profile_id' => $expensive->id,
            'price_cents' => 1_000,
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.produtos.variantes.precos', $this->product));

        $this->assertGreaterThan(700, $variant->refresh()->price_cents);
    }

    public function test_the_product_modal_shows_the_suggested_prices_per_variant(): void
    {
        $variant = Variant::factory()->for($this->product)->create([
            'material_id' => $this->material->id,
            'filament_weight_grams' => 50,
            'printing_time_minutes' => 180,
        ]);
        $blank = Variant::factory()->for($this->product)->create();

        $this->actingAs($this->admin)
            ->get(route('admin.produtos.index', ['editar' => $this->product->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('editing.variants', function ($variants) use ($variant, $blank): bool {
                    $rows = collect($variants)->keyBy('id');

                    return $rows[$variant->id]['suggestedRetailCents'] === 700
                        && $rows[$variant->id]['suggestedWholesaleCents'] === 400
                        && $rows[$blank->id]['suggestedRetailCents'] === null
                        && $rows[$blank->id]['suggestedWholesaleCents'] === null;
                }));
    }

    public function test_only_admins_can_touch_production_data(): void
    {
        $customer = User::factory()->create();
        $variant = Variant::factory()->for($this->product)->create(['price_cents' => 1_000]);

        $this->actingAs($customer)
            ->patch(route('admin.produtos.variantes.producao', $this->product), [
                'hours' => 1,
                'minutes' => 0,
                'weight_grams' => 10,
            ])
            ->assertForbidden();

        $this->actingAs($customer)
            ->post(route('admin.produtos.variantes.precos', $this->product))
            ->assertForbidden();

        $this->assertNull($variant->refresh()->printing_time_minutes);
        $this->assertSame(1_000, $variant->price_cents);
    }
}
