<?php

namespace Tests\Feature\Admin;

use App\Http\Controllers\Admin\ProductController;
use App\Models\Category;
use App\Models\Color;
use App\Models\Material;
use App\Models\PrinterProfile;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Tag;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * O routes/web.php diz que "middleware sozinho nao e seguranca", e os 21 Form
 * Requests do backoffice cumprem-no. As accoes destrutivas eram a excepcao:
 * apagar, restaurar, promover a principal e limpar etiquetas nao recebem corpo
 * de pedido, por isso nao tinham Form Request nenhum e ficavam so com o alias
 * `admin` a protege-las.
 *
 * Nao era explorável — o alias esta la e o AdminAccessTest guarda-o. Era a
 * barreira UNICA: bastava uma rota sair do grupo numa reorganizacao do ficheiro
 * para `DELETE /admin/clientes/{id}` ficar aberto a qualquer conta autenticada.
 *
 * Este teste percorre-as todas com um utilizador autenticado que NAO e
 * administrador e exige 403 em cada uma.
 */
class DestructiveActionAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string, string}>
     */
    public static function destructiveRoutes(): array
    {
        return [
            'apagar produto' => ['delete', 'produtos'],
            'restaurar produto' => ['patch', 'produtos-restaurar'],
            'apagar variante' => ['delete', 'variantes'],
            'apagar cliente' => ['delete', 'clientes'],
            'apagar categoria' => ['delete', 'categorias'],
            'restaurar categoria' => ['patch', 'categorias-restaurar'],
            'apagar cor' => ['delete', 'cores'],
            'restaurar cor' => ['patch', 'cores-restaurar'],
            'apagar material' => ['delete', 'materiais'],
            'restaurar material' => ['patch', 'materiais-restaurar'],
            'apagar impressora' => ['delete', 'impressoras'],
            'restaurar impressora' => ['patch', 'impressoras-restaurar'],
            'impressora predefinida' => ['patch', 'impressoras-predefinida'],
            'apagar etiqueta' => ['delete', 'etiquetas'],
            'limpar etiquetas' => ['delete', 'etiquetas-limpar'],
            'apagar fotografia' => ['delete', 'imagens'],
            'fotografia principal' => ['patch', 'imagens-principal'],
        ];
    }

    #[DataProvider('destructiveRoutes')]
    public function test_a_non_admin_is_refused(string $verb, string $key): void
    {
        $this->actingAs(User::factory()->create());

        $this->{$verb}($this->uriFor($key))->assertForbidden();
    }

    /**
     * A outra metade: o 403 tem mesmo de vir da autorizacao e nao de a rota nao
     * existir. Sem isto, um URI mal escrito no provider acima dava 404, o teste
     * passava a verde e nao guardava nada.
     */
    #[DataProvider('destructiveRoutes')]
    public function test_an_admin_is_not_refused(string $verb, string $key): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $response = $this->{$verb}($this->uriFor($key));

        $this->assertNotSame(
            403,
            $response->getStatusCode(),
            "Um administrador levou 403 em {$key}: a autorizacao esta a recusar quem devia deixar passar.",
        );
    }

    /**
     * O teste decisivo — e o unico que prova alguma coisa sobre a SEGUNDA
     * camada.
     *
     * Os dois acima passariam na mesma sem Form Request nenhum: o alias `admin`
     * ja devolve 403 a quem nao e administrador, por isso nao distinguem uma
     * barreira de duas. Este encena exatamente o acidente contra o qual a
     * segunda existe — a accao alcancada por uma rota FORA do grupo `admin` —
     * e exige que continue recusada.
     *
     * Se alguem tirar o AdminActionRequest da assinatura do destroy, e este que
     * fica vermelho.
     */
    public function test_the_action_refuses_a_non_admin_even_outside_the_admin_middleware(): void
    {
        Route::middleware(['web', 'auth'])->delete(
            '_test/produto-sem-cadeado/{product}',
            [ProductController::class, 'destroy'],
        );

        $product = Product::factory()->create(['status' => 'active']);

        $this->actingAs(User::factory()->create())
            ->delete('_test/produto-sem-cadeado/'.$product->getKey())
            ->assertForbidden();

        // Neste projeto "apagar" um produto e arquiva-lo (ProductService::archive)
        // — nao ha soft delete. O que interessa e que o estado nao mexeu.
        $this->assertSame('active', $product->refresh()->status);
    }

    public function test_the_same_route_still_works_for_an_admin(): void
    {
        // Controlo: o 403 acima e sobre quem faz o pedido, nao sobre a rota
        // improvisada estar mal montada.
        Route::middleware(['web', 'auth'])->delete(
            '_test/produto-sem-cadeado/{product}',
            [ProductController::class, 'destroy'],
        );

        $product = Product::factory()->create(['status' => 'active']);

        $this->actingAs(User::factory()->admin()->create())
            ->delete('_test/produto-sem-cadeado/'.$product->getKey())
            ->assertRedirect();

        $this->assertSame('archived', $product->refresh()->status);
    }

    private function uriFor(string $key): string
    {
        return match ($key) {
            'produtos' => '/admin/produtos/'.Product::factory()->create()->getKey(),
            'produtos-restaurar' => '/admin/produtos/'.Product::factory()->create()->getKey().'/restaurar',
            'variantes' => '/admin/variantes/'.Variant::factory()->create()->getKey(),
            'clientes' => '/admin/clientes/'.User::factory()->create()->getKey(),
            'categorias' => '/admin/categorias/'.Category::factory()->create()->getKey(),
            'categorias-restaurar' => '/admin/categorias/'.Category::factory()->create()->getKey().'/restaurar',
            'cores' => '/admin/cores/'.Color::factory()->create()->getKey(),
            'cores-restaurar' => '/admin/cores/'.Color::factory()->create()->getKey().'/restaurar',
            'materiais' => '/admin/materiais/'.Material::factory()->create()->getKey(),
            'materiais-restaurar' => '/admin/materiais/'.Material::factory()->create()->getKey().'/restaurar',
            'impressoras' => '/admin/impressoras/'.PrinterProfile::factory()->create()->getKey(),
            'impressoras-restaurar' => '/admin/impressoras/'.PrinterProfile::factory()->create()->getKey().'/restaurar',
            'impressoras-predefinida' => '/admin/impressoras/'.PrinterProfile::factory()->create()->getKey().'/predefinida',
            'etiquetas' => '/admin/etiquetas/'.Tag::factory()->create()->getKey(),
            'etiquetas-limpar' => '/admin/etiquetas/nao-usadas',
            'imagens' => '/admin/imagens/'.ProductImage::factory()->create()->getKey(),
            'imagens-principal' => '/admin/imagens/'.ProductImage::factory()->create()->getKey().'/principal',
        };
    }
}
