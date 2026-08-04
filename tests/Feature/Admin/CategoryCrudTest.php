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
                ->where('categories.0.name', 'Decoração'));
    }

    public function test_store_creates_category_with_ascii_slug(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.categorias.store'), [
                'name' => 'Decoração de Natal',
                'description' => 'Peças festivas',
                'active' => true,
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
            'active' => true,
            'sort_order' => 0,
        ]);

        $this->assertDatabaseHas('categories', ['slug' => 'gadgets-2']);
    }

    public function test_update_changes_slug_only_when_name_changes(): void
    {
        $category = Category::query()->create(['name' => 'Gadgets', 'slug' => 'gadgets']);

        $this->actingAs($this->admin)->patch(route('admin.categorias.update', $category), [
            'name' => 'Gadgets',
            'description' => 'Atualizada',
            'active' => true,
            'sort_order' => 3,
        ]);

        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'slug' => 'gadgets',
            'description' => 'Atualizada',
            'sort_order' => 3,
        ]);

        $this->actingAs($this->admin)->patch(route('admin.categorias.update', $category), [
            'name' => 'Utilidades',
            'active' => true,
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

        $this->actingAs($this->admin)
            ->delete(route('admin.categorias.destroy', $category))
            ->assertRedirect(route('admin.categorias.index'));

        // Regra global: nunca hard-delete — a linha continua la, inativa.
        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'active' => false,
        ]);
    }

    public function test_validation_rejects_missing_name(): void
    {
        $this->actingAs($this->admin)
            ->from(route('admin.categorias.create'))
            ->post(route('admin.categorias.store'), ['name' => ''])
            ->assertSessionHasErrors('name');
    }

    public function test_non_admins_cannot_touch_categories(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('admin.categorias.index'))
            ->assertForbidden();

        $this->actingAs($user)
            ->post(route('admin.categorias.store'), ['name' => 'Intrusa'])
            ->assertForbidden();

        $this->assertDatabaseMissing('categories', ['name' => 'Intrusa']);
    }
}
