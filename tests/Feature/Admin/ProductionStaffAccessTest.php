<?php

namespace Tests\Feature\Admin;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route as RouteFacade;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A equipa de producao ve o quadro e mais nada do /admin.
 *
 * O teste que importa e o primeiro: percorre TODAS as rotas `admin.*` e exige
 * que cada uma esteja atras do porteiro certo. Uma rota nova acrescentada ao
 * grupo errado (ou fora de grupo nenhum) falha aqui, antes de chegar a
 * producao.
 */
class ProductionStaffAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * As unicas rotas do /admin abertas a producao.
     */
    private const PRODUCTION_ROUTES = ['admin.producao', 'admin.itens.producao'];

    public function test_every_admin_route_is_behind_the_right_gate(): void
    {
        $adminRoutes = collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn (Route $route): bool => str_starts_with((string) $route->getName(), 'admin.'));

        $this->assertNotEmpty($adminRoutes);

        foreach ($adminRoutes as $route) {
            $name = (string) $route->getName();
            $middleware = $route->gatherMiddleware();

            if (in_array($name, self::PRODUCTION_ROUTES, true)) {
                $this->assertContains('production', $middleware, "{$name} devia estar atras do `production`.");
                $this->assertNotContains('admin', $middleware, "{$name} fechava a porta a producao.");

                continue;
            }

            if (str_starts_with($name, 'admin.utilizadores.')) {
                $this->assertContains('owner', $middleware, "{$name} devia ser so do dono.");
                $this->assertContains('password.confirm', $middleware, "{$name} devia pedir a password.");

                continue;
            }

            $this->assertContains('admin', $middleware, "{$name} nao esta atras do `admin`.");
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function adminPages(): array
    {
        return [
            'painel' => ['/admin'],
            'encomendas' => ['/admin/encomendas'],
            'clientes' => ['/admin/clientes'],
            'produtos' => ['/admin/produtos'],
            'definicoes' => ['/admin/definicoes'],
            'calculadora' => ['/admin/calculadora'],
            'equipa' => ['/admin/utilizadores'],
        ];
    }

    #[DataProvider('adminPages')]
    public function test_production_staff_cannot_open_the_rest_of_the_backoffice(string $uri): void
    {
        $this->actingAs(User::factory()->production()->create())
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get($uri)
            ->assertForbidden();
    }

    public function test_production_staff_can_see_the_board(): void
    {
        $this->actingAs(User::factory()->production()->create())
            ->get(route('admin.producao'))
            ->assertOk();
    }

    public function test_production_staff_can_move_a_card(): void
    {
        Mail::fake();

        $item = OrderItem::factory()->madeToOrder()->create([
            'order_id' => Order::factory()->paid()->create()->id,
        ]);

        $staff = User::factory()->production()->create();

        $this->actingAs($staff)
            ->patch(route('admin.itens.producao', $item), ['production_status' => 'printing'])
            ->assertRedirect();

        $this->assertSame('printing', $item->refresh()->production_status);
    }

    public function test_customers_still_cannot_see_the_board(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('admin.producao'))
            ->assertForbidden();
    }

    public function test_a_disabled_production_account_is_logged_out(): void
    {
        $this->actingAs(User::factory()->production()->disabled()->create())
            ->get(route('admin.producao'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_production_staff_land_on_the_board_after_login(): void
    {
        $this->actingAs(User::factory()->production()->create())
            ->get(route('dashboard'))
            ->assertRedirect(route('admin.producao'));
    }

    public function test_the_board_hides_nothing_it_should_not_share(): void
    {
        $this->actingAs(User::factory()->production()->create())
            ->get(route('admin.producao'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('auth.can.backoffice', false)
                ->where('auth.can.production', true)
                ->where('auth.can.manageStaff', false));
    }
}
