<?php

namespace Tests\Feature\Admin;

use App\Models\Color;
use App\Models\Material;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ColorCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Material $material;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->material = Material::factory()->create(['price_per_kg_cents' => 2190]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(): array
    {
        return [
            'material_id' => $this->material->id,
            'name' => 'Preto mate',
            'hex_color' => '#1a1a1a',
            'price_per_kg' => null,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function test_a_colour_without_its_own_price_inherits_the_material(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.cores.store'), $this->validPayload())
            ->assertRedirect(route('admin.cores.index'));

        $color = Color::query()->firstOrFail();

        $this->assertNull($color->price_per_kg_cents);
        $this->assertSame(2190, $color->effectivePricePerKgCents());
    }

    public function test_a_colour_can_override_the_material_price(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.cores.store'), [
                ...$this->validPayload(),
                'price_per_kg' => '29,90',
            ]);

        $color = Color::query()->firstOrFail();

        $this->assertSame(2990, $color->price_per_kg_cents);
        $this->assertSame(2990, $color->effectivePricePerKgCents());
    }

    /**
     * "Preto" em PLA e "Preto" em PETG sao linhas distintas — o indice
     * colors_material_name_unique so proibe repetir dentro do MESMO material.
     */
    public function test_the_same_name_is_allowed_in_a_different_material(): void
    {
        Color::factory()->create([
            'material_id' => $this->material->id,
            'name' => 'Preto mate',
        ]);

        $other = Material::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('admin.cores.store'), [
                ...$this->validPayload(),
                'material_id' => $other->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('colors', 2);
    }

    public function test_a_duplicate_name_in_the_same_material_is_rejected(): void
    {
        Color::factory()->create([
            'material_id' => $this->material->id,
            'name' => 'Preto mate',
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.cores.store'), $this->validPayload())
            ->assertSessionHasErrors('name');

        $this->assertDatabaseCount('colors', 1);
    }

    public function test_a_hex_without_the_hash_is_accepted(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.cores.store'), [
                ...$this->validPayload(),
                'hex_color' => 'FF7A00',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('#FF7A00', Color::query()->firstOrFail()->hex_color);
    }

    public function test_a_malformed_hex_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.cores.store'), [
                ...$this->validPayload(),
                'hex_color' => 'azul',
            ])
            ->assertSessionHasErrors('hex_color');
    }

    public function test_destroy_archives_and_keeps_the_variants(): void
    {
        $color = Color::factory()->create(['material_id' => $this->material->id]);
        $variant = Variant::factory()->create([
            'product_id' => Product::factory(),
            'color_id' => $color->id,
        ]);

        $this->actingAs($this->admin)
            ->delete(route('admin.cores.destroy', $color))
            ->assertRedirect(route('admin.cores.index'));

        $this->assertDatabaseHas('colors', ['id' => $color->id, 'is_active' => false]);
        $this->assertSame($color->id, $variant->refresh()->color_id);
    }

    /**
     * Ao editar uma cor cujo material foi entretanto arquivado, o material
     * tem de continuar no seletor — senao ele abria vazio e uma gravacao
     * inocente mudava a cor de material.
     */
    public function test_editing_keeps_an_archived_material_in_the_options(): void
    {
        $archived = Material::factory()->archived()->create();
        $color = Color::factory()->create(['material_id' => $archived->id]);

        $this->actingAs($this->admin)
            ->get(route('admin.cores.edit', $color))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('materials', fn ($materials): bool => collect($materials)
                    ->pluck('id')
                    ->contains($archived->id))
                ->where('color.materialId', $archived->id));
    }

    public function test_the_create_page_only_offers_active_materials(): void
    {
        Material::factory()->archived()->create();

        $this->actingAs($this->admin)
            ->get(route('admin.cores.create'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('materials', 1));
    }

    public function test_non_admins_cannot_touch_colours(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('admin.cores.store'), $this->validPayload())
            ->assertForbidden();

        $this->assertDatabaseCount('colors', 0);
    }
}
