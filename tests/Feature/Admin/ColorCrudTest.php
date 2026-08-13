<?php

namespace Tests\Feature\Admin;

use App\Models\Color;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Uma cor e uma linha: um nome unico global e um tom. Nao tem material nem
 * preco — quem tem os dois e a bobine.
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

    public function test_non_admins_cannot_touch_colours(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('admin.cores.store'), $this->validPayload())
            ->assertForbidden();

        $this->assertDatabaseCount('colors', 0);
    }
}
