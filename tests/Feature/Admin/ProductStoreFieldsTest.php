<?php

namespace Tests\Feature\Admin;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O que o produto ganhou para a montra: o texto dos motores de busca, a
 * ordem nos destaques e o texto de pesquisa normalizado.
 */
class ProductStoreFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_seo_fields_and_sort_order_are_saved(): void
    {
        $product = Product::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->patch(route('admin.produtos.update', $product), [
                'name' => $product->name,
                'status' => 'active',
                'vat_rate' => 23,
                'fulfillment_mode' => 'in_stock',
                'featured' => true,
                'sort_order' => 3,
                'seo_title' => 'Vaso para plantas pequenas',
                'seo_description' => 'Impresso em PLA, em várias cores.',
            ])
            ->assertSessionHasNoErrors();

        $product->refresh();
        $this->assertSame(3, $product->sort_order);
        $this->assertSame('Vaso para plantas pequenas', $product->seo_title);
        $this->assertSame('Impresso em PLA, em várias cores.', $product->seo_description);
    }

    public function test_seo_fields_have_limits(): void
    {
        $product = Product::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->patch(route('admin.produtos.update', $product), [
                'name' => $product->name,
                'status' => 'active',
                'vat_rate' => 23,
                'fulfillment_mode' => 'in_stock',
                'seo_title' => str_repeat('a', 71),
                'seo_description' => str_repeat('a', 171),
            ])
            ->assertSessionHasErrors(['seo_title', 'seo_description']);
    }

    public function test_the_search_text_follows_the_name_and_description(): void
    {
        $product = Product::factory()->create(['name' => 'Peça Única', 'description' => '<p>Feita à <strong>mão</strong></p>']);

        $this->assertSame('peca unica feita a mao', $product->search_text);

        $product->update(['name' => 'Candeeiro']);
        $this->assertSame('candeeiro feita a mao', $product->refresh()->search_text);
    }
}
