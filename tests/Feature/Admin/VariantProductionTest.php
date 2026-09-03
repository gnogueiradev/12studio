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
 * A aba "Producao" do produto: tempos e gramagens de todas as variantes de uma
 * vez, e os precos calculados a partir deles.
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

    public function test_it_saves_print_time_and_weight_for_every_variant_at_once(): void
    {
        $small = Variant::factory()->for($this->product)->create();
        $large = Variant::factory()->for($this->product)->create();

        $this->actingAs($this->admin)
            ->from(route('admin.produtos.index', ['editar' => $this->product->id]))
            ->patch(route('admin.produtos.variantes.producao', $this->product), [
                'rows' => [
                    ['id' => $small->id, 'hours' => 1, 'minutes' => 30, 'weight_grams' => 40],
                    ['id' => $large->id, 'hours' => 4, 'minutes' => 0, 'weight_grams' => 120],
                ],
            ])
            ->assertRedirect(route('admin.produtos.index', ['editar' => $this->product->id]));

        $this->assertSame(90, $small->refresh()->printing_time_minutes);
        $this->assertSame(40, $small->filament_weight_grams);
        $this->assertSame(240, $large->refresh()->printing_time_minutes);
        $this->assertSame(120, $large->filament_weight_grams);
    }

    /**
     * Um campo vazio apaga o valor — e diferente de zero, que e uma peca que
     * nao existe. So assim se limpa um engano.
     */
    public function test_an_empty_field_clears_the_value(): void
    {
        $variant = Variant::factory()->for($this->product)->create([
            'printing_time_minutes' => 90,
            'filament_weight_grams' => 40,
        ]);

        $this->actingAs($this->admin)
            ->patch(route('admin.produtos.variantes.producao', $this->product), [
                'rows' => [
                    ['id' => $variant->id, 'hours' => null, 'minutes' => null, 'weight_grams' => null],
                ],
            ])
            ->assertSessionHasNoErrors();

        $this->assertNull($variant->refresh()->printing_time_minutes);
        $this->assertNull($variant->filament_weight_grams);
    }

    public function test_it_refuses_a_variant_that_belongs_to_another_product(): void
    {
        $foreign = Variant::factory()->create(['printing_time_minutes' => 10]);

        $this->actingAs($this->admin)
            ->patch(route('admin.produtos.variantes.producao', $this->product), [
                'rows' => [
                    ['id' => $foreign->id, 'hours' => 2, 'minutes' => 0, 'weight_grams' => 50],
                ],
            ])
            ->assertSessionHasErrors('rows.0.id');

        $this->assertSame(10, $foreign->refresh()->printing_time_minutes);
    }

    public function test_it_validates_each_row_like_the_calculator_does(): void
    {
        $variant = Variant::factory()->for($this->product)->create();

        $this->actingAs($this->admin)
            ->patch(route('admin.produtos.variantes.producao', $this->product), [
                'rows' => [
                    ['id' => $variant->id, 'hours' => 1, 'minutes' => 75, 'weight_grams' => -5],
                ],
            ])
            ->assertSessionHasErrors(['rows.0.minutes', 'rows.0.weight_grams']);
    }

    public function test_applying_prices_writes_the_calculated_prices_on_every_calculable_variant(): void
    {
        $priced = Variant::factory()->for($this->product)->create([
            'material_id' => $this->material->id,
            'filament_weight_grams' => 50,
            'printing_time_minutes' => 180,
            'price_cents' => 1_000,
            'wholesale_price_cents' => null,
        ]);
        $noTime = Variant::factory()->for($this->product)->create([
            'material_id' => $this->material->id,
            'filament_weight_grams' => 50,
            'printing_time_minutes' => null,
            'price_cents' => 1_000,
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

        $this->assertSame(700, $priced->refresh()->price_cents);
        $this->assertSame(400, $priced->wholesale_price_cents);
        $this->assertNull($priced->compare_at_cents);
        // Sem tempo nao ha calculo; sem material o plastico seria de graca.
        $this->assertSame(1_000, $noTime->refresh()->price_cents);
        $this->assertSame(1_000, $noMaterial->refresh()->price_cents);
    }

    public function test_applying_prices_leaves_archived_variants_alone(): void
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

        $this->assertSame(1_000, $archived->refresh()->price_cents);
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
                'rows' => [['id' => $variant->id, 'hours' => 1, 'minutes' => 0, 'weight_grams' => 10]],
            ])
            ->assertForbidden();

        $this->actingAs($customer)
            ->post(route('admin.produtos.variantes.precos', $this->product))
            ->assertForbidden();
    }
}
