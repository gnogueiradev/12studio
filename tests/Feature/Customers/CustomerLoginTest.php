<?php

namespace Tests\Feature\Customers;

use App\Jobs\IssueWelcomePromoCode;
use App\Mail\CustomerLoginLinkMail;
use App\Models\CustomerLoginToken;
use App\Models\Order;
use App\Models\User;
use App\Services\SettingService;
use App\Services\StoreSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Feature\Stripe\FakesStripe;
use Tests\TestCase;

/**
 * Entrar com um link no email: a resposta e sempre a mesma, abrir o link nao
 * gasta nada (os leitores de email abrem-no sozinhos), so o botao entra, e uma
 * vez. A equipa nunca entra por aqui, e sair da conta de cliente nao tira
 * ninguem do backoffice no mesmo browser.
 */
class CustomerLoginTest extends TestCase
{
    use FakesStripe;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['access.store_open' => true]);
        Mail::fake();
        $this->fakeStripe();
        app(SettingService::class)->set(StoreSettings::KEY_SHOP, 'on');
    }

    /** Um link valido, como o `send` o criaria. */
    private function tokenFor(string $email): string
    {
        $token = Str::random(64);

        CustomerLoginToken::query()->create([
            'email' => $email,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes(20),
        ]);

        return $token;
    }

    /** @return TestResponse<Response> */
    private function enter(string $token): TestResponse
    {
        return $this->from('/entrar/'.$token)->post('/entrar/'.$token.'/confirmar');
    }

    public function test_the_form_answers_the_same_for_any_email(): void
    {
        $customer = User::factory()->create(['email' => 'ana@exemplo.pt']);
        $admin = User::factory()->admin()->create(['email' => 'chefe@exemplo.pt']);

        $answers = [];

        foreach (['ninguem@exemplo.pt', ' ANA@exemplo.pt ', $admin->email] as $email) {
            $response = $this->from('/entrar')->post('/entrar/enviar', ['email' => $email]);

            $response->assertRedirect('/entrar');
            $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
            $response->assertHeader('Referrer-Policy', 'no-referrer');
            $answers[] = [$response->headers->get('Location'), session('inertia.flash_data.toast')];
        }

        $this->assertSame([$answers[0], $answers[0], $answers[0]], $answers);
        $this->assertSame('success', $answers[0][1]['type']);

        Mail::assertQueued(CustomerLoginLinkMail::class, 2);
        Mail::assertQueued(CustomerLoginLinkMail::class, fn (CustomerLoginLinkMail $mail): bool => $mail->hasTo('ninguem@exemplo.pt'));
        Mail::assertQueued(CustomerLoginLinkMail::class, fn (CustomerLoginLinkMail $mail): bool => $mail->hasTo($customer->email));
        Mail::assertNotQueued(CustomerLoginLinkMail::class, fn (CustomerLoginLinkMail $mail): bool => $mail->hasTo($admin->email));

        // So o hash fica na base de dados, e o link do email e o da pagina
        // de confirmacao, que nao gasta nada.
        $mail = Mail::queued(CustomerLoginLinkMail::class, fn (CustomerLoginLinkMail $mail): bool => $mail->hasTo('ana@exemplo.pt'))->sole();
        $this->assertMatchesRegularExpression('#/entrar/([A-Za-z0-9]{64})$#', $mail->url);
        $token = Str::afterLast($mail->url, '/');
        $row = CustomerLoginToken::query()->where('email', 'ana@exemplo.pt')->sole();
        $this->assertSame(hash('sha256', $token), $row->token_hash);
        $this->assertEqualsWithDelta(now()->addMinutes(20)->getTimestamp(), $row->expires_at->getTimestamp(), 2);
        $this->assertSame(0, CustomerLoginToken::query()->where('email', $admin->email)->count());
    }

    public function test_the_form_always_returns_to_the_login_page(): void
    {
        // Sem Referer (a pagina nao o manda), ou com um de fora: volta sempre
        // ao /entrar do idioma, nunca para onde o pedido diz que veio.
        $this->post('/entrar/enviar', ['email' => 'ana@exemplo.pt'])->assertRedirect('/entrar');
        $this->from('https://outro.example/armadilha')->post('/en/entrar/enviar', ['email' => 'ana@exemplo.pt'])
            ->assertRedirect('/en/entrar');
    }

    public function test_the_form_is_rate_limited_per_email(): void
    {
        foreach (range(1, 3) as $ignored) {
            $this->from('/entrar')->post('/entrar/enviar', ['email' => 'ana@exemplo.pt'])->assertRedirect('/entrar');
        }

        // O mesmo limite para qualquer email, exista ou nao: um 429 nao diz
        // nada sobre a conta, e nao sai mais nenhum email.
        $this->from('/entrar')->post('/entrar/enviar', ['email' => 'ANA@exemplo.pt'])->assertTooManyRequests();
        $this->from('/entrar')->post('/entrar/enviar', ['email' => 'rui@exemplo.pt'])->assertRedirect('/entrar');

        Mail::assertQueued(CustomerLoginLinkMail::class, 4);
    }

    public function test_opening_the_link_does_not_sign_in(): void
    {
        $token = $this->tokenFor('ana@exemplo.pt');

        $this->get('/entrar/'.$token)
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertInertia(fn (Assert $page) => $page
                ->component('store/account/confirm')
                ->where('email', 'a***@exemplo.pt')
                ->where('token', $token));

        $this->assertGuest('customer');
        $this->assertNull(CustomerLoginToken::query()->sole()->used_at);
        $this->assertSame(0, User::query()->count());
    }

    public function test_posting_the_link_signs_in_once(): void
    {
        $customer = User::factory()->create(['email' => 'ana@exemplo.pt']);
        $token = $this->tokenFor('ana@exemplo.pt');

        $response = $this->enter($token);

        $response->assertRedirect('/conta');
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $response->assertCookie(Auth::guard('customer')->getRecallerName());
        $this->assertAuthenticatedAs($customer, 'customer');
        $this->assertGuest('web');
        $this->assertNotNull(CustomerLoginToken::query()->sole()->used_at);

        Auth::guard('customer')->logout();

        $this->enter($token)
            ->assertRedirect('/entrar')
            ->assertSessionHas('inertia.flash_data.toast.type', 'error')
            ->assertSessionHas('inertia.flash_data.toast.message', trans('store.account.link_invalid'));
        $this->assertGuest('customer');

        // Ja usado, a pagina do link tambem o diz.
        $this->get('/entrar/'.$token)->assertOk()->assertInertia(fn (Assert $page) => $page->where('email', null));
    }

    public function test_an_expired_link_fails(): void
    {
        $token = $this->tokenFor('ana@exemplo.pt');

        $this->travel(21)->minutes();

        $this->get('/entrar/'.$token)->assertOk()->assertInertia(fn (Assert $page) => $page->where('email', null));
        $this->enter($token)->assertRedirect('/entrar');
        $this->assertGuest('customer');
        $this->assertSame(0, User::query()->count());
    }

    public function test_the_first_entry_creates_the_customer_and_links_old_orders_by_email(): void
    {
        $other = User::factory()->create(['email' => 'rui@exemplo.pt']);
        $first = Order::factory()->create(['email' => 'Ana@Exemplo.pt', 'user_id' => null]);
        $second = Order::factory()->paid()->create(['email' => 'ana@exemplo.pt', 'user_id' => null]);
        $linked = Order::factory()->create(['email' => 'Ana@Exemplo.pt', 'user_id' => $other->id]);

        $this->enter($this->tokenFor('ana@exemplo.pt'))->assertRedirect('/conta');

        $ana = User::query()->where('email', 'ana@exemplo.pt')->sole();
        $this->assertAuthenticatedAs($ana, 'customer');
        $this->assertFalse($ana->isStaff());
        $this->assertNotNull($ana->email_verified_at);
        $this->assertSame($ana->id, $first->fresh()?->user_id);
        $this->assertSame($ana->id, $second->fresh()?->user_id);
        $this->assertSame($other->id, $linked->fresh()?->user_id);
    }

    public function test_an_existing_customer_without_a_proven_email_gets_the_old_orders_once(): void
    {
        $ana = User::factory()->unverified()->create(['email' => 'ana@exemplo.pt']);
        $order = Order::factory()->create(['email' => 'ANA@exemplo.pt', 'user_id' => null]);

        $this->enter($this->tokenFor('ana@exemplo.pt'))->assertRedirect('/conta');

        $this->assertAuthenticatedAs($ana, 'customer');
        $this->assertNotNull($ana->fresh()?->email_verified_at);
        $this->assertSame($ana->id, $order->fresh()?->user_id);

        // Depois de provado, uma encomenda nova sem conta ja nao muda de dono
        // so por se entrar outra vez.
        $later = Order::factory()->create(['email' => 'ana@exemplo.pt', 'user_id' => null]);
        Auth::guard('customer')->logout();
        $this->enter($this->tokenFor('ana@exemplo.pt'))->assertRedirect('/conta');

        $this->assertNull($later->fresh()?->user_id);
    }

    public function test_the_first_entry_sends_the_welcome_code_only_without_paid_orders(): void
    {
        Queue::fake([IssueWelcomePromoCode::class]);
        Order::factory()->paid()->create(['email' => 'Rui@Exemplo.pt']);

        $this->enter($this->tokenFor('ana@exemplo.pt'))->assertRedirect('/conta');
        Auth::guard('customer')->logout();
        $this->enter($this->tokenFor('rui@exemplo.pt'))->assertRedirect('/conta');
        Auth::guard('customer')->logout();

        Queue::assertPushed(IssueWelcomePromoCode::class, 1);
        Queue::assertPushed(IssueWelcomePromoCode::class, fn (IssueWelcomePromoCode $job): bool => $job->email === 'ana@exemplo.pt');

        // A segunda entrada ja nao e a primeira prova do email.
        $this->enter($this->tokenFor('ana@exemplo.pt'))->assertRedirect('/conta');

        Queue::assertPushed(IssueWelcomePromoCode::class, 1);
    }

    public function test_a_partially_refunded_order_also_counts_as_bought(): void
    {
        Queue::fake([IssueWelcomePromoCode::class]);
        Order::factory()->paid()->create(['email' => 'ana@exemplo.pt', 'payment_status' => 'partially_refunded']);

        $this->enter($this->tokenFor('ana@exemplo.pt'))->assertRedirect('/conta');

        Queue::assertNotPushed(IssueWelcomePromoCode::class);
    }

    public function test_staff_never_enters_by_link(): void
    {
        $admin = User::factory()->admin()->create(['email' => 'chefe@exemplo.pt']);
        $production = User::factory()->production()->create(['email' => 'oficina@exemplo.pt']);
        $disabled = User::factory()->disabled()->create(['email' => 'saiu@exemplo.pt']);

        foreach ([$admin, $production, $disabled] as $user) {
            $this->from('/entrar')->post('/entrar/enviar', ['email' => $user->email])->assertRedirect('/entrar');

            // Mesmo com um link na mao (criado antes de a conta mudar).
            $token = $this->tokenFor((string) $user->email);
            $this->enter($token)->assertRedirect('/entrar');
            $this->assertGuest('customer');
        }

        Mail::assertNothingQueued();
        $this->assertSame(3, User::query()->count());
    }

    public function test_customer_logout_keeps_the_staff_session(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->create();

        // Pela sessao e nao so em memoria (actingAs): um invalidate() no
        // logout levava a sessao da equipa, e e isso que se prova aqui.
        Auth::guard('web')->login($admin);
        Auth::guard('customer')->login($customer);

        $this->post('/conta/sair')->assertRedirect('/');

        $this->assertAuthenticatedAs($admin, 'web');
        $this->assertGuest('customer');
        $this->assertSame($admin->id, session(Auth::guard('web')->getName()));
        $this->assertNull(session(Auth::guard('customer')->getName()));
    }

    public function test_the_login_is_behind_the_shop_gate(): void
    {
        $token = $this->tokenFor('ana@exemplo.pt');

        foreach (['off', 'staff'] as $mode) {
            app(SettingService::class)->set(StoreSettings::KEY_SHOP, $mode);

            $this->get('/entrar')->assertNotFound();
            $this->post('/entrar/enviar', ['email' => 'ana@exemplo.pt'])->assertNotFound();
            $this->get('/entrar/'.$token)->assertNotFound();
            $this->post('/entrar/'.$token.'/confirmar')->assertNotFound();
        }

        Mail::assertNothingQueued();
        $this->assertNull(CustomerLoginToken::query()->sole()->used_at);

        // Em "so para a equipa", a equipa ve a pagina (o porteiro usa a guarda
        // `web`), e um cliente com sessao nao passa a porta por isso.
        $this->actingAs(User::factory()->admin()->create(), 'web');
        $this->get('/entrar')->assertOk()->assertInertia(fn (Assert $page) => $page->component('store/account/login'));
    }

    public function test_the_storefront_knows_the_customer(): void
    {
        $this->get('/entrar')->assertInertia(fn (Assert $page) => $page->where('storefront.customer', null));

        $customer = User::factory()->create(['name' => 'Ana Silva']);
        Auth::guard('customer')->login($customer);

        $this->get('/entrar')->assertInertia(fn (Assert $page) => $page->where('storefront.customer', ['name' => 'Ana Silva']));
    }

    public function test_the_language_prefix_keeps_the_link_in_that_language(): void
    {
        $this->from('/en/entrar')->post('/en/entrar/enviar', ['email' => 'ana@exemplo.pt'])->assertRedirect('/en/entrar');

        $mail = Mail::queued(CustomerLoginLinkMail::class)->sole();
        $this->assertMatchesRegularExpression('#/en/entrar/[A-Za-z0-9]{64}$#', $mail->url);
        $this->assertStringStartsWith(rtrim((string) config('app.url'), '/').'/', $mail->url);

        $token = $this->tokenFor('ana@exemplo.pt');
        $this->from('/en/entrar/'.$token)->post('/en/entrar/'.$token.'/confirmar')->assertRedirect('/en/conta');
    }
}
