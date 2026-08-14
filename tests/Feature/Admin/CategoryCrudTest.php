<?php

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CategoryCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    public function test_index_lists_categories(): void
    {
        Category::query()->create(['name' => 'Decoração', 'slug' => 'decoracao']);

        $this->actingAs($this->admin)
            ->get(route('admin.categorias.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/categorias/index')
                ->has('categories', 1)
                ->where('categories.0.name', 'Decoração')
                ->where('categories.0.status', 'visible'));
    }

    public function test_store_creates_category_with_ascii_slug(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.categorias.store'), [
                'name' => 'Decoração de Natal',
                'description' => 'Peças festivas',
                'status' => 'visible',
                'sort_order' => 1,
            ])
            ->assertRedirect(route('admin.categorias.index'));

        // Slug ASCII sem acentos — regra do plano.
        $this->assertDatabaseHas('categories', [
            'name' => 'Decoração de Natal',
            'slug' => 'decoracao-de-natal',
        ]);
    }

    public function test_store_resolves_slug_collisions(): void
    {
        Category::query()->create(['name' => 'Gadgets', 'slug' => 'gadgets']);

        $this->actingAs($this->admin)->post(route('admin.categorias.store'), [
            'name' => 'Gadgets',
            'status' => 'visible',
            'sort_order' => 0,
        ]);

        $this->assertDatabaseHas('categories', ['slug' => 'gadgets-2']);
    }

    public function test_store_accepts_a_colour_from_the_palette(): void
    {
        $this->actingAs($this->admin)->post(route('admin.categorias.store'), [
            'name' => 'Iluminação',
            'status' => 'visible',
            'color' => '#D9A84E',
            'sort_order' => 0,
        ]);

        $this->assertDatabaseHas('categories', [
            'slug' => 'iluminacao',
            'color' => '#D9A84E',
        ]);
    }

    /**
     * A paleta deixou de ser fechada: os sete tons do design sao atalhos do
     * seletor, nao a lista do que pode entrar. Um tom apanhado no espectro e
     * uma escolha legitima — o seletor avisa se o contraste cair, e a cor vive
     * numa bolinha decorativa que nao tem minimo nenhum a cumprir.
     */
    public function test_store_accepts_a_colour_outside_the_palette(): void
    {
        $this->actingAs($this->admin)->post(route('admin.categorias.store'), [
            'name' => 'Fluorescente',
            'status' => 'visible',
            'color' => '#00FF00',
            'sort_order' => 0,
        ]);

        $this->assertDatabaseHas('categories', [
            'name' => 'Fluorescente',
            'color' => '#00FF00',
        ]);
    }

    /**
     * O que continua a nao entrar e o que nao e um hex: a coluna e varchar(7) e
     * o frontend pinta-a directamente num `style`.
     */
    public function test_store_rejects_anything_that_is_not_a_plain_hex(): void
    {
        foreach (['verde', '#GG0000', '#FFF', '#FF7A00CC', 'rgb(0,0,0)'] as $value) {
            $this->actingAs($this->admin)
                ->from(route('admin.categorias.index'))
                ->post(route('admin.categorias.store'), [
                    'name' => 'Fluorescente',
                    'status' => 'visible',
                    'color' => $value,
                    'sort_order' => 0,
                ])
                ->assertSessionHasErrors('color');
        }

        $this->assertDatabaseMissing('categories', ['name' => 'Fluorescente']);
    }

    public function test_store_rejects_an_unknown_status(): void
    {
        $this->actingAs($this->admin)
            ->from(route('admin.categorias.index'))
            ->post(route('admin.categorias.store'), [
                'name' => 'Intermedia',
                'status' => 'talvez',
                'sort_order' => 0,
            ])
            ->assertSessionHasErrors('status');
    }

    public function test_update_changes_slug_only_when_name_changes(): void
    {
        $category = Category::query()->create(['name' => 'Gadgets', 'slug' => 'gadgets']);

        $this->actingAs($this->admin)->patch(route('admin.categorias.update', $category), [
            'name' => 'Gadgets',
            'description' => 'Atualizada',
            'status' => 'hidden',
            'sort_order' => 3,
        ]);

        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'slug' => 'gadgets',
            'description' => 'Atualizada',
            'status' => 'hidden',
            'sort_order' => 3,
        ]);

        $this->actingAs($this->admin)->patch(route('admin.categorias.update', $category), [
            'name' => 'Utilidades',
            'status' => 'visible',
            'sort_order' => 3,
        ]);

        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'name' => 'Utilidades',
            'slug' => 'utilidades',
        ]);
    }

    public function test_destroy_archives_instead_of_deleting(): void
    {
        $category = Category::query()->create(['name' => 'Gadgets', 'slug' => 'gadgets']);

        // Arquiva-se de dentro de uma vista filtrada, e o `back()` do
        // controller tem de a devolver tal como estava.
        $filtered = route('admin.categorias.index', ['status' => 'visible']);

        $this->actingAs($this->admin)
            ->from($filtered)
            ->delete(route('admin.categorias.destroy', $category))
            ->assertRedirect($filtered);

        // Regra global: nunca hard-delete — a linha continua la, arquivada.
        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'status' => 'archived',
        ]);
    }

    public function test_restore_brings_an_archived_category_back(): void
    {
        $category = Category::query()->create([
            'name' => 'Natal 2025',
            'slug' => 'natal-2025',
            'status' => 'archived',
        ]);

        $filtered = route('admin.categorias.index', ['status' => 'archived']);

        $this->actingAs($this->admin)
            ->from($filtered)
            ->patch(route('admin.categorias.restaurar', $category))
            ->assertRedirect($filtered);

        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'status' => 'visible',
        ]);
    }

    public function test_validation_rejects_missing_name(): void
    {
        $this->actingAs($this->admin)
            ->from(route('admin.categorias.index'))
            ->post(route('admin.categorias.store'), ['name' => '', 'status' => 'visible'])
            ->assertSessionHasErrors('name');
    }

    public function test_non_admins_cannot_touch_categories(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('admin.categorias.index'))
            ->assertForbidden();

        $this->actingAs($user)
            ->post(route('admin.categorias.store'), ['name' => 'Intrusa', 'status' => 'visible'])
            ->assertForbidden();

        $this->assertDatabaseMissing('categories', ['name' => 'Intrusa']);
    }
}
