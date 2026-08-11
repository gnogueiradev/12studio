<?php

namespace Tests\Feature;

use App\Models\NotifySubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotifySubscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_visitor_can_leave_an_email(): void
    {
        $this->post(route('notify'), ['email' => 'ana@exemplo.pt'])
            ->assertRedirect();

        $this->assertDatabaseHas('notify_subscriptions', [
            'email' => 'ana@exemplo.pt',
        ]);
    }

    /**
     * "Ana@Exemplo.PT" e "ana@exemplo.pt" sao a mesma pessoa. Sem normalizar,
     * o indice unico da tabela deixava entrar as duas.
     */
    public function test_the_email_is_stored_in_lowercase(): void
    {
        $this->post(route('notify'), ['email' => '  Ana@Exemplo.PT  ']);

        $this->assertDatabaseHas('notify_subscriptions', [
            'email' => 'ana@exemplo.pt',
        ]);
    }

    /**
     * Repetir o envio nao pode rebentar contra o indice unico nem responder
     * "este email ja esta registado" — isso fazia da landing um oraculo de
     * quem subscreveu.
     */
    public function test_submitting_the_same_email_twice_is_silently_idempotent(): void
    {
        NotifySubscription::factory()->create(['email' => 'ana@exemplo.pt']);

        $this->post(route('notify'), ['email' => 'ana@exemplo.pt'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(1, NotifySubscription::query()->count());
    }

    public function test_an_invalid_email_is_rejected(): void
    {
        $this->post(route('notify'), ['email' => 'isto-nao-e-um-email'])
            ->assertSessionHasErrors('email');

        $this->assertSame(0, NotifySubscription::query()->count());
    }

    public function test_the_email_is_required(): void
    {
        $this->post(route('notify'), [])
            ->assertSessionHasErrors('email');
    }
}
