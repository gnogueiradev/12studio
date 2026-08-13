<?php

namespace Tests\Feature\Admin;

use App\Models\Color;
use App\Models\Material;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaterialCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(): array
    {
        return [
            'name' => 'PLA',
            'family' => 'PLA',
            'supplier' => 'Prusament',
            'price_per_kg' => '21.90',
            'spools_in_stock' => 9,
            'min_spools' => 4,
            'active' => true,
            'sort_order' => 0,
        ];
    }

    public function test_store_converts_euros_to_cents(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.materiais.store'), [
                ...$this->validPayload(),
                'price_per_kg' => '21,90',
            ])
            ->assertRedirect(route('admin.materiais.index'));

        $this->assertDatabaseHas('materials', [
            'name' => 'PLA',
            'price_per_kg_cents' => 2190,
        ]);
    }

    public function test_duplicate_name_is_rejected(): void
    {
        Material::factory()->create(['name' => 'PLA']);

        $this->actingAs($this->admin)
            ->post(route('admin.materiais.store'), $this->validPayload())
            ->assertSessionHasErrors('name');
    }

    public function test_a_material_can_keep_its_own_name_when_edited(): void
    {
        $material = Material::factory()->create(['name' => 'PLA']);

        $this->actingAs($this->admin)
            ->patch(route('admin.materiais.update', $material), [
                ...$this->validPayload(),
                'price_per_kg' => '24.50',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(2450, $material->refresh()->price_per_kg_cents);
    }

    /**
     * Regra global de eliminacao logica: as cores e as variantes que ja usam
     * o material tem de sobreviver ao arquivo.
     */
    public function test_destroy_archives_and_keeps_the_colours(): void
    {
        $material = Material::factory()->create();
        $color = Color::factory()->create(['material_id' => $material->id]);

        $this->actingAs($this->admin)
            ->delete(route('admin.materiais.destroy', $material))
            ->assertRedirect(route('admin.materiais.index'));

        $this->assertDatabaseHas('materials', ['id' => $material->id, 'active' => false]);
        $this->assertDatabaseHas('colors', ['id' => $color->id, 'is_active' => true]);
    }

    public function test_restore_makes_an_archived_material_selectable_again(): void
    {
        $material = Material::factory()->archived()->create();

        $this->actingAs($this->admin)
            ->patch(route('admin.materiais.restaurar', $material))
            ->assertRedirect();

        $this->assertTrue($material->refresh()->active);
    }

    /**
     * O material e as suas primeiras cores nascem juntos, senao ficava um
     * material que nao gera variante nenhuma.
     */
    public function test_store_creates_the_selected_colours(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.materiais.store'), [
                ...$this->validPayload(),
                'colors' => [
                    ['name' => 'Preto', 'hex' => '#1A1715'],
                    ['name' => 'Dourado', 'hex' => '#D9A84E'],
                ],
            ])
            ->assertSessionHasNoErrors();

        $material = Material::query()->where('name', 'PLA')->sole();

        $this->assertSame(2, $material->colors()->count());
        $this->assertDatabaseHas('colors', [
            'material_id' => $material->id,
            'name' => 'Preto',
            'hex_color' => '#1A1715',
            // Sem override: a cor herda o preco/kg que o modal acabou de pedir.
            'price_per_kg_cents' => null,
        ]);
    }

    /**
     * O coracao do modal: uma cor que ainda nao existe em lado nenhum nasce
     * aqui, sem passar por /admin/cores.
     */
    public function test_store_creates_a_colour_invented_in_the_modal(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.materiais.store'), [
                ...$this->validPayload(),
                'colors' => [['name' => 'Rosa neon', 'hex' => '#FF4FA3']],
            ])
            ->assertSessionHasNoErrors();

        $material = Material::query()->where('name', 'PLA')->sole();

        $this->assertDatabaseHas('colors', [
            'material_id' => $material->id,
            'name' => 'Rosa neon',
            'hex_color' => '#FF4FA3',
            'price_per_kg_cents' => null,
            'sort_order' => 0,
        ]);
    }

    /**
     * O hex do formulario so vale para nomes novos. Numa cor que ja existe
     * ganha o do catalogo, senao criar um material com "Preto" a branco partia
     * o grupo que /admin/cores mostra como uma cor so.
     */
    public function test_store_keeps_the_hex_of_a_colour_that_already_exists(): void
    {
        Color::factory()->create(['name' => 'Rosa neon', 'hex_color' => '#FF4FA3']);

        $this->actingAs($this->admin)
            ->post(route('admin.materiais.store'), [
                ...$this->validPayload(),
                'colors' => [['name' => 'rosa neon', 'hex' => '#000000']],
            ])
            ->assertSessionHasNoErrors();

        $material = Material::query()->where('name', 'PLA')->sole();

        // O nome tambem e o do catalogo: gravar "rosa neon" partia o grupo em
        // dois na listagem de cores.
        $this->assertDatabaseHas('colors', [
            'material_id' => $material->id,
            'name' => 'Rosa neon',
            'hex_color' => '#FF4FA3',
        ]);
    }

    public function test_store_rejects_an_invalid_hex(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.materiais.store'), [
                ...$this->validPayload(),
                'colors' => [['name' => 'Rosa neon', 'hex' => '#GGGGGG']],
            ])
            ->assertSessionHasErrors('colors.0.hex');

        $this->assertDatabaseMissing('materials', ['name' => 'PLA']);
    }

    public function test_store_rejects_the_same_colour_twice(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.materiais.store'), [
                ...$this->validPayload(),
                'colors' => [
                    ['name' => 'Preto', 'hex' => '#1A1715'],
                    ['name' => 'preto', 'hex' => '#000000'],
                ],
            ])
            ->assertSessionHasErrors('colors.0.name');

        $this->assertDatabaseMissing('materials', ['name' => 'PLA']);
    }

    /**
     * O admin tanto usa o seletor de cor como cola "FF4FA3" de um site de
     * paletas — mesmo remendo que o formulario de /admin/cores ja tinha.
     */
    public function test_store_accepts_a_hex_pasted_without_the_hash(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.materiais.store'), [
                ...$this->validPayload(),
                'colors' => [['name' => 'Rosa neon', 'hex' => 'FF4FA3']],
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('colors', [
            'name' => 'Rosa neon',
            'hex_color' => '#FF4FA3',
        ]);
    }

    /**
     * O `sort_order` e a ordem por que o admin ligou as chips — o unico sinal
     * de preferencia que o formulario da.
     */
    public function test_store_keeps_the_order_of_the_chosen_colours(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.materiais.store'), [
                ...$this->validPayload(),
                'colors' => [
                    ['name' => 'Dourado', 'hex' => '#D9A84E'],
                    ['name' => 'Preto', 'hex' => '#1A1715'],
                    ['name' => 'Bege', 'hex' => '#C6A77B'],
                ],
            ])
            ->assertSessionHasNoErrors();

        $material = Material::query()->where('name', 'PLA')->sole();

        $this->assertSame(
            ['Dourado', 'Preto', 'Bege'],
            $material->colors()->orderBy('sort_order')->pluck('name')->all(),
        );
    }

    /**
     * As cores sao um atalho de criacao. Um material que ja existe tem cores
     * proprias, com precos e imagens tratados em /admin/cores — gravar o
     * formulario de edicao nao lhes pode mexer.
     *
     * Manda `colors` de proposito: e este teste que apanha o UpdateMaterialRequest
     * a excluir chaves que deixaram de existir.
     */
    public function test_update_ignores_colours(): void
    {
        $material = Material::factory()->create(['name' => 'PLA']);

        $this->actingAs($this->admin)
            ->patch(route('admin.materiais.update', $material), [
                ...$this->validPayload(),
                'colors' => [
                    ['name' => 'Preto', 'hex' => '#1A1715'],
                    ['name' => 'Branco', 'hex' => '#FAF8F5'],
                ],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $material->colors()->count());
    }

    public function test_non_admins_cannot_touch_materials(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('admin.materiais.store'), $this->validPayload())
            ->assertForbidden();

        $this->assertDatabaseMissing('materials', ['name' => 'PLA']);
    }
}
