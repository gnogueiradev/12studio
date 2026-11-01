<?php

namespace Tests\Feature\Store;

use App\Models\Page;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * O que o editor desenha na pagina (zonas de largar, nomes dos elementos
 * vazios, a camada de edicao) nunca chega a quem visita a loja.
 */
class PublicPageCanvasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['access.store_open' => true]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $children
     * @return array<string, mixed>
     */
    private function container(string $id, array $children = []): array
    {
        return [
            'id' => $id,
            'type' => 'container',
            'v' => 2,
            'visible' => true,
            'settings' => [],
            'content' => [],
            'style' => [],
            'advanced' => [],
            'children' => $children,
        ];
    }

    public function test_a_visitor_gets_none_of_the_editing_canvas(): void
    {
        Page::factory()->published([
            $this->container('vazio'),
            $this->container('com-titulo', [[
                'id' => 'titulo',
                'type' => 'heading',
                'v' => 1,
                'visible' => true,
                'variant' => null,
                'shell' => false,
                'style' => [],
                'styleProps' => [],
                'advanced' => [],
                'settings' => [],
                'content' => ['pt' => ['text' => '']],
            ]]),
        ])->create(['slug' => 'campanha', 'name' => 'Campanha']);

        $this->get('/campanha')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('store/page')
                ->where('page.preview', false)
                // Os nós vazios existem mesmo: é o browser que não os enfeita.
                ->where('page.blocks.0.id', 'vazio')
                ->has('page.blocks.0.children', 0)
                ->where('page.blocks.1.children.0.id', 'titulo'))
            ->assertDontSee('data-pb-empty', false)
            ->assertDontSee('data-pb-collapsed', false)
            ->assertDontSee('Arrasta um elemento', false)
            ->assertDontSee('canvas-overlay', false);
    }
}
