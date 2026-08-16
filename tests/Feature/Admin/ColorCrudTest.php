<?php

namespace Tests\Feature\Admin;

use App\Models\Color;
use App\Models\Material;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Uma cor e uma linha: um nome unico global, um tom, e os filamentos em que
 * existe. Nao tem preco — quem custa dinheiro e a bobine.
 */
class ColorCrudTest extends TestCase
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
            'name' => 'Preto mate',
            'hex_color' => '#1a1a1a',
        ];
    }

    public function test_store_creates_a_colour(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.cores.store'), $this->validPayload())
            ->assertRedirect(route('admin.cores.index'));

        $this->assertDatabaseHas('colors', [
            'name' => 'Preto mate',
            'hex_color' => '#1a1a1a',
            'is_active' => true,
        ]);
    }

    /**
     * O indice colors_name_unique e global: a cor e o nome, e um nome so pode
     * querer dizer uma coisa.
     */
    public function test_two_colours_cannot_share_a_name(): void
    {
        Color::factory()->create(['name' => 'Preto mate']);

        $this->actingAs($this->admin)
            ->post(route('admin.cores.store'), $this->validPayload())
            ->assertSessionHasErrors('name');

        $this->assertDatabaseCount('colors', 1);
    }

    /**
     * O indice e COLLATE NOCASE e a validacao usa lower(): sem isto, "preto
     * mate" passava a validacao e rebentava no INSERT.
     */
    public function test_two_colours_cannot_share_a_name_ignoring_case(): void
    {
        Color::factory()->create(['name' => 'PRETO MATE']);

        $this->actingAs($this->admin)
            ->post(route('admin.cores.store'), $this->validPayload())
            ->assertSessionHasErrors('name');

        $this->assertDatabaseCount('colors', 1);
    }

    /**
     * Criar por cima de uma arquivada com o mesmo nome tem de RESTAURAR a
     * linha. Um insert cego batia no colors_name_unique — que nao sabe de
     * `is_active` — e devolvia um erro de base de dados a quem so queria gravar
     * o formulario.
     */
    public function test_creating_where_an_archived_row_exists_restores_it(): void
    {
        $archived = Color::factory()->archived()->create([
            'name' => 'Preto mate',
            'hex_color' => '#000000',
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.cores.store'), $this->validPayload())
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('colors', 1);
        $this->assertDatabaseHas('colors', [
            'id' => $archived->id,
            'is_active' => true,
            'hex_color' => '#1a1a1a',
        ]);
    }

    public function test_editing_renames_the_colour(): void
    {
        $color = Color::factory()->create([
            'name' => 'Preto mate',
            'hex_color' => '#1a1a1a',
        ]);

        $this->actingAs($this->admin)
            ->put(route('admin.cores.update', $color), [
                'name' => 'Grafite',
                'hex_color' => '#2B2B2B',
            ])
            ->assertRedirect(route('admin.cores.index'));

        $this->assertDatabaseHas('colors', [
            'id' => $color->id,
            'name' => 'Grafite',
            'hex_color' => '#2B2B2B',
        ]);
    }

    /**
     * A unicidade ignora a propria cor — sem isso nenhuma se conseguia gravar
     * duas vezes seguidas sem lhe mudar o nome.
     */
    public function test_editing_without_renaming_is_allowed(): void
    {
        $color = Color::factory()->create(['name' => 'Preto mate']);

        $this->actingAs($this->admin)
            ->put(route('admin.cores.update', $color), [
                'name' => 'Preto mate',
                'hex_color' => '#111111',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('colors', [
            'id' => $color->id,
            'hex_color' => '#111111',
        ]);
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

    public function test_destroy_archives_the_colour_and_keeps_the_variants(): void
    {
        $color = Color::factory()->create();
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

    public function test_restore_brings_the_colour_back(): void
    {
        $color = Color::factory()->archived()->create();

        $this->actingAs($this->admin)
            ->patch(route('admin.cores.restaurar', $color))
            ->assertRedirect();

        $this->assertDatabaseHas('colors', ['id' => $color->id, 'is_active' => true]);
    }

    /**
     * Desmarcar um filamento esconde as variantes que o usavam — nunca as
     * apaga. As encomendas antigas ainda lhes apontam, e a `variants.color_id`
     * e restrictOnDelete por isso mesmo.
     */
    public function test_unmarking_a_filament_hides_the_variants_that_used_it(): void
    {
        [$pla, $silk] = $this->twoFilaments();
        $rosa = Color::factory()->withMaterials([$pla, $silk])->create(['name' => 'Rosa']);

        $emSilk = $this->variantOf($rosa, $silk);
        $emPla = $this->variantOf($rosa, $pla);

        $this->actingAs($this->admin)
            ->patch(route('admin.cores.update', $rosa), [
                'name' => 'Rosa',
                'hex_color' => '#ff88aa',
                'material_ids' => [$pla->id],
            ])
            ->assertSessionHasNoErrors();

        $this->assertFalse($emSilk->refresh()->active);
        $this->assertTrue($emSilk->hidden_by_palette);
        $this->assertTrue($emPla->refresh()->active);
    }

    /** Comprar rosa silk outra vez traz de volta o que o catalogo escondeu. */
    public function test_remarking_a_filament_brings_those_variants_back(): void
    {
        [$pla, $silk] = $this->twoFilaments();
        $rosa = Color::factory()->withMaterials([$pla])->create(['name' => 'Rosa']);

        $emSilk = $this->variantOf($rosa, $silk, ['active' => false, 'hidden_by_palette' => true]);

        $this->actingAs($this->admin)
            ->patch(route('admin.cores.update', $rosa), [
                'name' => 'Rosa',
                'hex_color' => '#ff88aa',
                'material_ids' => [$pla->id, $silk->id],
            ]);

        $this->assertTrue($emSilk->refresh()->active);
        $this->assertFalse($emSilk->hidden_by_palette);
    }

    /**
     * A distincao que justifica a coluna `hidden_by_palette`: uma variante que
     * o dono desligou a mao nao ressuscita quando o filamento volta. Sem ela,
     * remarcar o Silk repunha a venda exactamente o que ele tinha tirado.
     */
    public function test_a_variant_hidden_by_hand_is_not_resurrected(): void
    {
        [$pla, $silk] = $this->twoFilaments();
        $rosa = Color::factory()->withMaterials([$pla])->create(['name' => 'Rosa']);

        $aMao = $this->variantOf($rosa, $silk, ['active' => false, 'hidden_by_palette' => false]);

        $this->actingAs($this->admin)
            ->patch(route('admin.cores.update', $rosa), [
                'name' => 'Rosa',
                'hex_color' => '#ff88aa',
                'material_ids' => [$pla->id, $silk->id],
            ]);

        $this->assertFalse($aMao->refresh()->active);
    }

    /**
     * Conjunto vazio e "ainda nao disse em que filamentos existe", nao "nao
     * existe em nenhum". Nao ter declarado nao autoriza a esconder o que ja se
     * vendia — e a diferenca que torna seguro comecar com a pivo vazia.
     */
    public function test_declaring_no_filament_touches_nothing(): void
    {
        [$pla, $silk] = $this->twoFilaments();
        $rosa = Color::factory()->withMaterials([$pla, $silk])->create(['name' => 'Rosa']);

        $emSilk = $this->variantOf($rosa, $silk);

        $this->actingAs($this->admin)
            ->patch(route('admin.cores.update', $rosa), [
                'name' => 'Rosa',
                'hex_color' => '#ff88aa',
                'material_ids' => [],
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue($emSilk->refresh()->active);
        $this->assertSame(0, $rosa->materials()->count());
    }

    /**
     * `material_ids` vem no mesmo payload mas nao e coluna de `colors`. Deixa-lo
     * chegar ao update rebentava com "column not found" a meio de uma gravacao
     * inocente.
     */
    public function test_the_filaments_do_not_leak_into_the_colours_table(): void
    {
        [$pla] = $this->twoFilaments();

        $this->actingAs($this->admin)
            ->post(route('admin.cores.store'), [
                ...$this->validPayload(),
                'material_ids' => [$pla->id],
            ])
            ->assertSessionHasNoErrors();

        $color = Color::query()->where('name', 'Preto mate')->sole();

        $this->assertSame([$pla->id], $color->materials()->pluck('materials.id')->all());
    }

    /** @return array{0: Material, 1: Material} */
    private function twoFilaments(): array
    {
        return [
            Material::factory()->create(['name' => 'PLA']),
            Material::factory()->create(['name' => 'PLA Silk']),
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function variantOf(Color $color, Material $material, array $overrides = []): Variant
    {
        return Variant::factory()->create([
            'product_id' => Product::factory(),
            'color_id' => $color->id,
            'material_id' => $material->id,
            ...$overrides,
        ]);
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
