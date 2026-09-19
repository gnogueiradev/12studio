<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\StudioServer;
use App\Mcp\Tools\Write\Catalog\CategoryCreateTool;
use App\Mcp\Tools\Write\Catalog\CategoryUpdateTool;
use App\Mcp\Tools\Write\Catalog\ColorCreateTool;
use App\Mcp\Tools\Write\Catalog\ColorUpdateTool;
use App\Mcp\Tools\Write\Catalog\MaterialCreateTool;
use App\Mcp\Tools\Write\Catalog\MaterialUpdateTool;
use App\Mcp\Tools\Write\Catalog\TagCreateTool;
use App\Models\Category;
use App\Models\Color;
use App\Models\Material;
use App\Models\McpActivity;
use App\Models\Tag;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\Testing\PendingTestResponse;
use Laravel\Passport\Passport;
use Tests\TestCase;

class CatalogWriteToolsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    private function writer(): PendingTestResponse
    {
        Passport::actingAs($this->admin, ['mcp:read', 'mcp:write']);

        return StudioServer::actingAs($this->admin);
    }

    public function test_catalog_writes_need_a_write_key(): void
    {
        Passport::actingAs($this->admin, ['mcp:read']);

        StudioServer::actingAs($this->admin)
            ->tool(CategoryCreateTool::class, ['name' => 'Vasos'])
            ->assertNotRegistered();
    }

    public function test_a_new_category_is_hidden_until_asked(): void
    {
        $this->writer()->tool(CategoryCreateTool::class, ['name' => 'Vasos de Parede'])->assertOk();

        $category = Category::query()->sole();
        $this->assertSame('hidden', $category->status);
        $this->assertSame('vasos-de-parede', $category->slug);
    }

    public function test_category_update_changes_only_what_was_sent(): void
    {
        $category = Category::factory()->create(['name' => 'Vasos', 'status' => 'hidden', 'description' => 'Fica']);

        $this->writer()->tool(CategoryUpdateTool::class, ['id' => $category->id, 'status' => 'visible'])->assertOk();

        $category->refresh();
        $this->assertSame('visible', $category->status);
        $this->assertSame('Fica', $category->description);
        $this->assertSame(['status' => 'hidden'], McpActivity::query()->where('tool', 'category_update')->sole()->changes['before']);
    }

    public function test_category_rules_come_from_the_backoffice(): void
    {
        $this->writer()->tool(CategoryCreateTool::class, ['name' => 'X', 'color' => 'vermelho'])->assertHasErrors();

        $this->assertSame(0, Category::query()->count());
    }

    public function test_tag_create_is_idempotent_and_never_for_customers(): void
    {
        $this->writer()->tool(TagCreateTool::class, ['name' => 'Natal'])->assertOk();
        $this->writer()->tool(TagCreateTool::class, ['name' => 'Natal'])->assertOk();

        $this->assertSame(1, Tag::query()->count());

        $this->writer()->tool(TagCreateTool::class, ['name' => 'VIP', 'scope' => 'customer'])->assertHasErrors();
        $this->assertSame(0, Tag::query()->where('scope', 'customer')->count());
    }

    public function test_color_create_with_materials(): void
    {
        $pla = Material::factory()->create(['name' => 'PLA']);

        $this->writer()->tool(ColorCreateTool::class, ['name' => 'Coral', 'hex_color' => 'FF7F50', 'material_ids' => [$pla->id]])
            ->assertOk()
            ->assertSee('PLA');

        $color = Color::query()->where('name', 'Coral')->sole();
        $this->assertSame('#FF7F50', $color->hex_color);
        $this->assertSame([$pla->id], $color->materials->pluck('id')->all());
    }

    public function test_color_names_stay_unique_ignoring_case(): void
    {
        Color::factory()->create(['name' => 'Preto']);

        $this->writer()->tool(ColorCreateTool::class, ['name' => 'preto', 'hex_color' => '#000000'])->assertHasErrors();
    }

    public function test_removing_a_material_that_hides_variants_needs_confirmation(): void
    {
        $pla = Material::factory()->create();
        $silk = Material::factory()->create();
        $rosa = Color::factory()->create(['name' => 'Rosa']);
        $rosa->materials()->attach([$pla->id, $silk->id]);
        $silkVariant = Variant::factory()->create(['color_id' => $rosa->id, 'material_id' => $silk->id, 'active' => true]);

        $this->writer()->tool(ColorUpdateTool::class, ['id' => $rosa->id, 'material_ids' => [$pla->id]])
            ->assertHasErrors()
            ->assertSee('esconde da loja 1 variante');

        $this->assertTrue($silkVariant->refresh()->active);
        $this->assertCount(2, $rosa->materials()->get());

        $this->writer()->tool(ColorUpdateTool::class, ['id' => $rosa->id, 'material_ids' => [$pla->id], 'confirm' => true])
            ->assertOk()
            ->assertSee('"variants_hidden":1');

        $this->assertFalse($silkVariant->refresh()->active);
    }

    public function test_color_update_without_materials_leaves_them_alone(): void
    {
        $pla = Material::factory()->create();
        $rosa = Color::factory()->create(['name' => 'Rosa']);
        $rosa->materials()->attach($pla);

        $this->writer()->tool(ColorUpdateTool::class, ['id' => $rosa->id, 'name' => 'Rosa Choque'])->assertOk();

        $this->assertSame('Rosa Choque', $rosa->refresh()->name);
        $this->assertCount(1, $rosa->materials()->get());
    }

    public function test_material_create_and_price_update(): void
    {
        $this->writer()->tool(MaterialCreateTool::class, ['name' => 'PETG', 'family' => 'PETG', 'price_per_kg' => '24,99'])->assertOk();

        $material = Material::query()->sole();
        $this->assertSame(2499, $material->price_per_kg_cents);
        $this->assertTrue($material->active);

        $this->writer()->tool(MaterialUpdateTool::class, ['id' => $material->id, 'price_per_kg' => '27,50'])->assertOk();

        $material->refresh();
        $this->assertSame(2750, $material->price_per_kg_cents);
        $this->assertSame('PETG', $material->name);

        $activity = McpActivity::query()->where('tool', 'material_update')->sole();
        $this->assertSame(['price_per_kg' => '24.99'], $activity->changes['before']);
        $this->assertSame(['price_per_kg' => '27.50'], $activity->changes['after']);
    }

    public function test_material_names_are_unique(): void
    {
        Material::factory()->create(['name' => 'PLA']);

        $this->writer()->tool(MaterialCreateTool::class, ['name' => 'PLA', 'price_per_kg' => '20'])->assertHasErrors();
    }
}
