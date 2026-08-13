<?php

namespace Tests\Feature\Admin;

use App\Models\Color;
use App\Models\Material;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O formulario gere a cor como UMA identidade; a tabela guarda uma linha por
 * par cor x material. Estes testes vivem quase todos nessa costura.
 */
class ColorCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Material $pla;

    private Material $petg;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->pla = Material::factory()->create(['name' => 'PLA', 'price_per_kg_cents' => 2190]);
        $this->petg = Material::factory()->create(['name' => 'PETG', 'price_per_kg_cents' => 2600]);
    }

    /**
     * @param  array<int, int>|null  $materialIds
     * @return array<string, mixed>
     */
    private function validPayload(?array $materialIds = null): array
    {
        return [
            'material_ids' => $materialIds ?? [$this->pla->id],
            'name' => 'Preto mate',
            'hex_color' => '#1a1a1a',
            'price_per_kg' => null,
        ];
    }

    public function test_a_colour_without_its_own_price_inherits_each_material(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.cores.store'), $this->validPayload([$this->pla->id, $this->petg->id]))
            ->assertRedirect(route('admin.cores.index'));

        $this->assertDatabaseCount('colors', 2);

        $colors = Color::query()->with('material')->get();

        $this->assertTrue($colors->every(fn (Color $color): bool => $color->price_per_kg_cents === null));
        $this->assertEqualsCanonicalizing(
            [2190, 2600],
            $colors->map(fn (Color $color): int => $color->effectivePricePerKgCents())->all(),
        );
    }

    /**
     * O leque: um nome e um hex espalhados por todos os materiais escolhidos.
     */
    public function test_creating_in_two_materials_writes_one_row_per_material(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.cores.store'), $this->validPayload([$this->pla->id, $this->petg->id]));

        $colors = Color::query()->get();

        $this->assertSame(['Preto mate', 'Preto mate'], $colors->pluck('name')->all());
        $this->assertSame(['#1a1a1a', '#1a1a1a'], $colors->pluck('hex_color')->all());
        $this->assertEqualsCanonicalizing(
            [$this->pla->id, $this->petg->id],
            $colors->pluck('material_id')->all(),
        );
    }

    public function test_a_colour_can_override_the_price_in_every_material(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.cores.store'), [
                ...$this->validPayload([$this->pla->id, $this->petg->id]),
                'price_per_kg' => '29,90',
            ]);

        $this->assertSame(
            [2990, 2990],
            Color::query()->pluck('price_per_kg_cents')->all(),
        );
    }

    /**
     * Re-adicionar um material de onde a cor tinha sido tirada tem de RESTAURAR
     * a linha arquivada. Um insert cego batia no colors_material_name_unique e
     * devolvia um erro de base de dados a quem so queria gravar o formulario.
     */
    public function test_creating_where_an_archived_row_exists_restores_it(): void
    {
        $archived = Color::factory()->archived()->create([
            'material_id' => $this->pla->id,
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

    public function test_editing_propagates_the_name_and_the_hex_to_the_whole_group(): void
    {
        $group = $this->group();

        $this->actingAs($this->admin)
            ->put(route('admin.cores.update', $group->first()), [
                'material_ids' => [$this->pla->id, $this->petg->id],
                'name' => 'Grafite',
                'hex_color' => '#2B2B2B',
                'price_per_kg' => null,
            ])
            ->assertRedirect(route('admin.cores.index'));

        $colors = Color::query()->get();

        $this->assertSame(['Grafite', 'Grafite'], $colors->pluck('name')->all());
        $this->assertSame(['#2B2B2B', '#2B2B2B'], $colors->pluck('hex_color')->all());
    }

    /**
     * Regra global de eliminacao: tirar um material do grupo arquiva a linha,
     * nunca a apaga. A variante que ja a usa continua a resolver a cor.
     */
    public function test_dropping_a_material_archives_that_row_and_keeps_its_variants(): void
    {
        $group = $this->group();
        $dropped = $group->firstWhere('material_id', $this->petg->id);
        $variant = Variant::factory()->create([
            'product_id' => Product::factory(),
            'color_id' => $dropped->id,
        ]);

        $this->actingAs($this->admin)
            ->put(route('admin.cores.update', $group->first()), [
                'material_ids' => [$this->pla->id],
                'name' => 'Preto mate',
                'hex_color' => '#1a1a1a',
                'price_per_kg' => null,
            ]);

        $this->assertDatabaseCount('colors', 2);
        $this->assertDatabaseHas('colors', ['id' => $dropped->id, 'is_active' => false]);
        $this->assertSame($dropped->id, $variant->refresh()->color_id);
    }

    /**
     * A linha que sai leva o nome novo: a cor foi renomeada, nao dividida em
     * duas — e e assim que o material volta a entrar no grupo se o
     * reactivarem, em vez de colidir com ele.
     */
    public function test_a_dropped_row_keeps_the_new_name(): void
    {
        $group = $this->group();

        $this->actingAs($this->admin)
            ->put(route('admin.cores.update', $group->first()), [
                'material_ids' => [$this->pla->id],
                'name' => 'Grafite',
                'hex_color' => '#2B2B2B',
                'price_per_kg' => null,
            ]);

        $this->assertSame(['Grafite', 'Grafite'], Color::query()->pluck('name')->all());
        $this->assertSame(
            [true, false],
            Color::query()->orderBy('material_id')->pluck('is_active')->all(),
        );
    }

    /**
     * So o que esta VIVO noutra cor e que colide. Uma linha arquivada com o
     * mesmo nome nao ocupa o nome — esta a espera de voltar, e e o caso do
     * teste acima.
     */
    public function test_a_name_already_used_in_one_of_the_materials_is_rejected(): void
    {
        Color::factory()->create([
            'material_id' => $this->petg->id,
            'name' => 'Preto mate',
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.cores.store'), $this->validPayload([$this->pla->id, $this->petg->id]))
            ->assertSessionHasErrors('name');

        $this->assertDatabaseCount('colors', 1);
    }

    /**
     * A unicidade ignora as linhas do proprio grupo — sem isso nenhuma cor se
     * conseguia gravar duas vezes seguidas sem lhe mudar o nome.
     */
    public function test_editing_without_renaming_is_allowed(): void
    {
        $group = $this->group();

        $this->actingAs($this->admin)
            ->put(route('admin.cores.update', $group->first()), [
                'material_ids' => [$this->pla->id, $this->petg->id],
                'name' => 'Preto mate',
                'hex_color' => '#111111',
                'price_per_kg' => '31,00',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame([3100, 3100], Color::query()->pluck('price_per_kg_cents')->all());
    }

    public function test_a_colour_needs_at_least_one_material(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.cores.store'), $this->validPayload([]))
            ->assertSessionHasErrors('material_ids');

        $this->assertDatabaseCount('colors', 0);
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

    public function test_destroy_archives_the_whole_group_and_keeps_the_variants(): void
    {
        $group = $this->group();
        $variant = Variant::factory()->create([
            'product_id' => Product::factory(),
            'color_id' => $group->first()->id,
        ]);

        $this->actingAs($this->admin)
            ->delete(route('admin.cores.destroy', $group->first()))
            ->assertRedirect(route('admin.cores.index'));

        $this->assertSame([false, false], Color::query()->pluck('is_active')->all());
        $this->assertSame($group->first()->id, $variant->refresh()->color_id);
    }

    public function test_restore_brings_the_whole_group_back(): void
    {
        $group = $this->group(archived: true);

        $this->actingAs($this->admin)
            ->patch(route('admin.cores.restaurar', $group->first()))
            ->assertRedirect();

        $this->assertSame([true, true], Color::query()->pluck('is_active')->all());
    }

    public function test_non_admins_cannot_touch_colours(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('admin.cores.store'), $this->validPayload())
            ->assertForbidden();

        $this->assertDatabaseCount('colors', 0);
    }

    /**
     * "Preto mate" nos dois materiais — o grupo com que quase todos estes
     * testes trabalham.
     *
     * @return Collection<int, Color>
     */
    private function group(bool $archived = false): Collection
    {
        foreach ([$this->pla, $this->petg] as $material) {
            Color::factory()->create([
                'material_id' => $material->id,
                'name' => 'Preto mate',
                'hex_color' => '#1a1a1a',
                'is_active' => ! $archived,
            ]);
        }

        return Color::query()->where('name', 'Preto mate')->orderBy('material_id')->get();
    }
}
