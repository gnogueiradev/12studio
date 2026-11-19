<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * O login da equipa (guarda `web`) e a recuperacao de password do Fortify so
 * servem contas da equipa. Com cada comprador a passar a ser um utilizador,
 * um cliente que pusesse uma password pelo "recuperar password" entrava no
 * backoffice sem segundo fator — essa porta fecha-se aqui.
 */
class StaffOnlyLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_customer_with_a_password_cannot_log_in(): void
    {
        $customer = User::factory()->create();

        $this->post(route('login.store'), [
            'email' => $customer->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest('web');
    }

    public function test_a_customer_gets_no_reset_email(): void
    {
        Notification::fake();

        $customer = User::factory()->create();
        $admin = User::factory()->admin()->create();

        $forCustomer = $this->resetLinkResponse($customer->email);
        $forUnknown = $this->resetLinkResponse('ninguem@exemplo.pt');
        $forStaff = $this->resetLinkResponse($admin->email);

        Notification::assertNotSentTo($customer, ResetPassword::class);
        Notification::assertSentTo($admin, ResetPassword::class);
        Notification::assertSentTimes(ResetPassword::class, 1);

        // A mesma resposta para um cliente, um email que nao existe e alguem
        // da equipa: quem pede nao fica a saber de quem e o email.
        $this->assertSame($forStaff, $forCustomer);
        $this->assertSame($forStaff, $forUnknown);
        $this->assertSame(trans('passwords.sent'), $forStaff['message']);
        $this->assertSame([], $forStaff['errors']);
    }

    public function test_a_throttled_reset_request_still_reports_the_error(): void
    {
        Notification::fake();

        $admin = User::factory()->admin()->create();

        $this->resetLinkResponse($admin->email);
        $again = $this->resetLinkResponse($admin->email);

        // So o "email desconhecido" passa a parecer um envio; as outras falhas
        // do broker (aqui, o link pedido ha menos de um minuto) continuam.
        $this->assertSame([trans('passwords.throttled')], $again['errors']['email'] ?? null);
        Notification::assertSentTimes(ResetPassword::class, 1);
    }

    public function test_a_customer_cannot_reset_with_an_existing_token(): void
    {
        $customer = User::factory()->create();
        $token = Password::broker()->createToken($customer);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $customer->email,
            'password' => 'Uma-password-bem-longa-42',
            'password_confirmation' => 'Uma-password-bem-longa-42',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(password_verify('password', (string) $customer->refresh()->password));
    }

    public function test_staff_still_logs_in_and_resets(): void
    {
        Notification::fake();

        $admin = User::factory()->admin()->create();

        $this->post(route('login.store'), [
            'email' => $admin->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($admin, 'web');

        $this->post(route('logout'));

        $this->post(route('password.email'), ['email' => $admin->email])
            ->assertSessionHas('status');

        Notification::assertSentTo($admin, ResetPassword::class);
    }

    public function test_a_customer_web_session_is_ended(): void
    {
        $customer = User::factory()->create();

        $this->actingAs($customer, 'web')
            ->get(route('dashboard'))
            ->assertRedirect(route('login'));

        $this->assertGuest('web');
    }

    public function test_a_customer_web_session_on_the_storefront_continues_as_a_guest(): void
    {
        $customer = User::factory()->create();

        // A montra nunca manda um cliente para o login escondido da equipa: a
        // sessao `web` acaba e o pedido segue como de um visitante.
        $response = $this->actingAs($customer, 'web')->get(route('home'));

        $this->assertFalse($response->isRedirect(route('login')));
        $this->assertGuest('web');
    }

    /**
     * Tudo o que quem pede o link consegue ver: o codigo, para onde volta, a
     * mensagem de estado e os erros.
     *
     * @return array{status: int, location: string|null, message: mixed, errors: array<string, mixed>}
     */
    private function resetLinkResponse(string $email): array
    {
        $response = $this->post(route('password.email'), ['email' => $email]);

        return [
            'status' => $response->getStatusCode(),
            'location' => $response->headers->get('Location'),
            'message' => session('status'),
            // A sessao guarda-se em JSON: os erros voltam como array e nao
            // como ViewErrorBag.
            'errors' => data_get(session('errors'), 'default.messages', []),
        ];
    }
}
