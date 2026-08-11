<?php

namespace Tests\Feature\Admin;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A descricao e o unico campo do backoffice que guarda HTML e acaba
 * renderizado com dangerouslySetInnerHTML. A limpeza acontece no
 * ProductService, ANTES de tocar na base de dados — o que fica guardado ja e
 * seguro, e nao ha leitura nenhuma por onde possa escapar.
 */
class ProductDescriptionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'Vaso ondulado',
            'status' => 'draft',
            'vat_rate' => 23,
            'fulfillment_mode' => 'in_stock',
            ...$overrides,
        ];
    }

    private function storeWithDescription(string $html): ?string
    {
        $this->actingAs($this->admin)
            ->post(route('admin.produtos.store'), $this->payload(['description' => $html]));

        return Product::query()->firstOrFail()->description;
    }

    public function test_allowed_formatting_survives(): void
    {
        $description = $this->storeWithDescription(
            '<p>Peça em <strong>PLA</strong> com acabamento <em>mate</em>.</p><ul><li>20 cm</li></ul>',
        );

        $this->assertStringContainsString('<strong>PLA</strong>', (string) $description);
        $this->assertStringContainsString('<em>mate</em>', (string) $description);
        $this->assertStringContainsString('<li>20 cm</li>', (string) $description);
    }

    public function test_scripts_are_stripped(): void
    {
        $description = (string) $this->storeWithDescription(
            '<p>Olá</p><script>alert(document.cookie)</script>',
        );

        $this->assertStringNotContainsString('<script', $description);
        $this->assertStringNotContainsString('alert(', $description);
        $this->assertStringContainsString('Olá', $description);
    }

    public function test_event_handlers_are_stripped(): void
    {
        $description = (string) $this->storeWithDescription(
            '<p onclick="steal()">Clica aqui</p>',
        );

        $this->assertStringNotContainsString('onclick', $description);
        $this->assertStringContainsString('Clica aqui', $description);
    }

    public function test_javascript_urls_are_stripped(): void
    {
        $description = (string) $this->storeWithDescription(
            '<p><a href="javascript:alert(1)">isto</a></p>',
        );

        $this->assertStringNotContainsString('javascript:', $description);
    }

    /**
     * Links para fora levam rel="noreferrer noopener" — o HTMLPurifier
     * acrescenta-o com HTML.TargetBlank ligado.
     */
    public function test_external_links_get_a_safe_rel(): void
    {
        $description = (string) $this->storeWithDescription(
            '<p><a href="https://exemplo.pt">exemplo</a></p>',
        );

        $this->assertStringContainsString('href="https://exemplo.pt"', $description);
        $this->assertStringContainsString('noopener', $description);
    }

    /**
     * O TipTap devolve "<p></p>" quando o editor esta vazio — isso e nada,
     * nao uma descricao com um paragrafo em branco.
     */
    public function test_an_empty_editor_stores_null(): void
    {
        $this->assertNull($this->storeWithDescription('<p></p>'));
    }
}
