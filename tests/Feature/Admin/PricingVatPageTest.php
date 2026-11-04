<?php

namespace Tests\Feature\Admin;

use App\Models\Material;
use App\Models\PrinterProfile;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Services\PricingSettings;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * O IVA atraves do HTTP: de onde vem cada uma das duas taxas.
 *
 * A da VENDA e do produto — e, na calculadora solta, a de config/shop.php. A
 * dos CUSTOS e uma definicao da casa. A conta em si esta fixada ao micro no
 * PricingVatTest; aqui prova-se so que a taxa certa chega ao calculo.
 *
 * A peca e sempre a de referencia: 50 g a 17 EUR/kg, 3 h na A1.
 */
class PricingVatPageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

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

        // A calculadora livre pedida como orcamento (quote=1: minimo de 20 min
        // de trabalho). Aqui prova-se so de onde vem cada taxa, com a peca de
        // referencia de 5 min — o mesmo custo do PricingVatTest. (Os precos da
        // calculadora levam ainda o lucro minimo do trabalho; os da variante
        // nao — o painel da variante ignora o quote.)
        app(SettingService::class)->set(PricingSettings::KEY_QUOTE_MIN_LABOR_MINUTES, 0);
    }

    /**
     * @return array<string, mixed>
     */
    private function referencePart(): array
    {
        return [
            'weight_grams' => 50,
            'hours' => 3,
            'minutes' => 0,
            'material_id' => $this->material->id,
            'quote' => 1,
        ];
    }

    private function variantOf(Product $product): Variant
    {
        return Variant::factory()->for($product)->create([
            'material_id' => $this->material->id,
            'filament_weight_grams' => 50,
            'printing_time_minutes' => 180,
            // Abaixo de qualquer sugestao: aplicar precos nunca baixa um preco
            // ja definido, e a fabrica sorteia um entre 5 e 99 EUR.
            'price_cents' => 100,
            'wholesale_price_cents' => null,
        ]);
    }

    /**
     * A calculadora solta nao tem produto: usa os 23% de config/shop.php na
     * venda e os 23% das definicoes nos custos.
     */
    public function test_the_calculator_prices_with_the_shop_default_rate(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.calculadora', $this->referencePart()))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('result.vatRateBp', 2_300)
                ->where('result.costVatRateBp', 2_300)
                ->where('result.filamentCostMicros', 691_057)
                ->where('result.productionCostMicros', 1_841_481)
                // Um orcamento de uma peca: o lucro minimo do trabalho manda.
                // Recomendado: (1,841481 + 8) x 1,23 = 12,105 -> 12,49;
                // revenda: 50% = 6,245 -> 6,49.
                ->where('result.wholesalePriceCents', 649)
                ->where('result.retailPriceCents', 1_249)
                ->where('result.wholesalePriceExVatCents', 528)
                ->where('result.retailPriceExVatCents', 1_015)
                ->where('result.retailVatCents', 234)
                ->where('result.wholesaleMarginBp', 6_510)
                ->where('result.directMarginBp', 8_187)
            );
    }

    /**
     * O painel de custo da ficha de variante vive na pagina de um produto, e e
     * a taxa DESSE produto que conta: a 6% a mesma peca sai a 4,49 e 8,49 EUR
     * (Recomendado: (1,841481 + 3 h x 1,90) x 1,06 = 7,994 -> 8,49; revenda:
     * 50% = 4,245 -> 4,49). A 23% seriam 4,99 e 9,49.
     */
    public function test_the_variant_panel_prices_with_the_rate_of_its_product(): void
    {
        $reduced = Product::factory()->create(['vat_rate' => 6]);

        $this->actingAs($this->admin)
            ->get(route('admin.produtos.edit', ['product' => $reduced, ...$this->referencePart()]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('pricing.result.vatRateBp', 600)
                ->where('pricing.result.productionCostMicros', 1_841_481)
                ->where('pricing.result.wholesalePriceCents', 449)
                ->where('pricing.result.retailPriceCents', 849)
            );
    }

    /**
     * O preco sugerido de cada variante gravada — o que a aba "Producao" mostra
     * e o "Aplicar precos" escreve — sai tambem com a taxa do produto dela.
     */
    public function test_the_suggested_prices_follow_the_rate_of_each_product(): void
    {
        $standard = Product::factory()->create(['vat_rate' => 23]);
        $reduced = Product::factory()->create(['vat_rate' => 6]);

        $this->variantOf($standard);
        $this->variantOf($reduced);

        $this->actingAs($this->admin)
            ->get(route('admin.produtos.edit', $standard))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('editing.variants.0.suggestedWholesaleCents', 499)
                ->where('editing.variants.0.suggestedRetailCents', 949)
            );

        $this->actingAs($this->admin)
            ->get(route('admin.produtos.edit', $reduced))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('editing.variants.0.suggestedWholesaleCents', 449)
                ->where('editing.variants.0.suggestedRetailCents', 849)
            );
    }

    public function test_applying_prices_writes_the_ones_for_the_rate_of_the_product(): void
    {
        $reduced = Product::factory()->create(['vat_rate' => 6]);
        $variant = $this->variantOf($reduced);

        $this->actingAs($this->admin)
            ->post(route('admin.produtos.variantes.precos', $reduced))
            ->assertRedirect();

        $this->assertSame(849, $variant->refresh()->price_cents);
        $this->assertSame(449, $variant->wholesale_price_cents);
    }

    /**
     * Quem escreve os custos ja sem IVA poe a taxa a zero nas definicoes, e a
     * calculadora deixa de lhes tirar o que la nao esta.
     */
    public function test_the_cost_rate_is_a_setting_the_admin_can_zero(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.definicoes.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('pricing.cost_vat_percent', '23.00'));

        $this->actingAs($this->admin)
            ->patch(route('admin.definicoes.precos'), [
                ...app(PricingSettings::class)->toForm(),
                'cost_vat_percent' => '0',
            ])
            ->assertRedirect(route('admin.definicoes.index'));

        $this->assertSame(0, app(PricingSettings::class)->costVatRateBp());

        $this->actingAs($this->admin)
            ->get(route('admin.calculadora', $this->referencePart()))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('result.costVatRateBp', 0)
                ->where('result.filamentCostMicros', 850_000)
                ->where('result.productionCostMicros', 2_103_618)
                // Custos ja sem IVA, orcamento: (2,103618 + 8) x 1,23 = 12,43
                // -> 12,49; revenda 6,49.
                ->where('result.wholesalePriceCents', 649)
                ->where('result.retailPriceCents', 1_249)
            );
    }

    public function test_the_cost_rate_is_required_and_bounded(): void
    {
        $form = app(PricingSettings::class)->toForm();

        $this->actingAs($this->admin)
            ->patch(route('admin.definicoes.precos'), [...$form, 'cost_vat_percent' => '150'])
            ->assertSessionHasErrors('cost_vat_percent');

        $this->actingAs($this->admin)
            ->patch(route('admin.definicoes.precos'), [...$form, 'cost_vat_percent' => ''])
            ->assertSessionHasErrors('cost_vat_percent');

        $this->assertSame(2_300, app(PricingSettings::class)->costVatRateBp());
    }
}
