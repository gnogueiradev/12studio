<?php

namespace Tests\Feature\Conversations;

use App\Mail\ConversationRepliedMail;
use App\Models\Conversation;
use App\Models\OrderDraft;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Services\ConversationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A caixa de entrada do backoffice: listar, ler, responder, notas internas,
 * fechar e passar a rascunho de encomenda.
 */
class AdminInboxTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['access.store_open' => true]);
        Mail::fake();
        Storage::fake('local');
        $this->admin = User::factory()->admin()->create(['name' => 'Gonçalo']);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: Conversation, 1: string}
     */
    private function conversation(array $data = []): array
    {
        return app(ConversationService::class)->open(array_merge([
            'type' => 'contact',
            'name' => 'Ana Silva',
            'email' => 'ana@example.com',
            'message' => 'Fazem vasos maiores?',
            'locale' => 'es',
        ], $data));
    }

    public function test_the_inbox_lists_conversations_and_filters_them(): void
    {
        $this->conversation();
        [$closed] = $this->conversation(['name' => 'Rui', 'email' => 'rui@example.com']);
        app(ConversationService::class)->close($closed);

        $this->actingAs($this->admin)
            ->get(route('admin.conversas.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/conversas/index')
                ->has('conversations.data', 2)
                ->where('counts.open', 1));

        $this->actingAs($this->admin)
            ->get(route('admin.conversas.index', ['estado' => 'closed']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('conversations.data', 1)
                ->where('conversations.data.0.name', 'Rui'));

        $this->actingAs($this->admin)
            ->get(route('admin.conversas.index', ['q' => 'ana@']))
            ->assertInertia(fn (Assert $page) => $page->has('conversations.data', 1));
    }

    public function test_only_admins_see_the_inbox(): void
    {
        [$conversation] = $this->conversation();

        $this->actingAs(User::factory()->create())->get(route('admin.conversas.index'))->assertForbidden();
        $this->actingAs(User::factory()->production()->create())->get(route('admin.conversas.show', $conversation))->assertForbidden();
    }

    public function test_the_sidebar_counts_unread_conversations(): void
    {
        [$conversation] = $this->conversation();

        $this->actingAs($this->admin)->get(route('admin.dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('inbox.unread', 1));

        $this->actingAs($this->admin)->get(route('admin.conversas.show', $conversation))->assertOk();

        $this->actingAs($this->admin)->get(route('admin.dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('inbox.unread', 0));

        $this->actingAs(User::factory()->production()->create())->get(route('admin.producao'))
            ->assertInertia(fn (Assert $page) => $page->where('inbox', null));
    }

    public function test_a_staff_reply_answers_and_emails_the_customer_in_their_language(): void
    {
        [$conversation] = $this->conversation();

        $this->actingAs($this->admin)
            ->post(route('admin.conversas.responder', $conversation), [
                'message' => 'Fazemos sim! Até 30 cm.',
                'files' => [UploadedFile::fake()->image('medidas.jpg')],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $conversation->refresh();
        $this->assertSame('answered', $conversation->status);
        $reply = $conversation->messages->last();
        $this->assertSame('staff', $reply->author);
        $this->assertSame($this->admin->id, $reply->user_id);
        $this->assertCount(1, $reply->attachments);

        Mail::assertQueued(ConversationRepliedMail::class, function (ConversationRepliedMail $mail): bool {
            $this->get('/conversa/'.$mail->token)->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('conversation.status', 'answered')
                ->has('conversation.messages', 2)
                ->where('conversation.messages.1.mine', false));

            return $mail->hasTo('ana@example.com') && $mail->locale === 'es';
        });
    }

    public function test_an_internal_note_stays_inside(): void
    {
        [$conversation, $token] = $this->conversation();

        $this->actingAs($this->admin)
            ->post(route('admin.conversas.responder', $conversation), ['message' => 'Cliente da feira.', 'internal' => '1'])
            ->assertRedirect();

        $this->assertSame('open', $conversation->fresh()->status);
        Mail::assertNotQueued(ConversationRepliedMail::class);

        auth()->logout();
        $this->get('/es/conversa/'.$token)->assertInertia(fn (Assert $page) => $page->has('conversation.messages', 1));
    }

    public function test_close_and_reopen(): void
    {
        [$conversation] = $this->conversation();

        $this->actingAs($this->admin)->post(route('admin.conversas.fechar', $conversation))->assertRedirect();
        $this->assertSame('closed', $conversation->fresh()->status);
        $this->assertNotNull($conversation->fresh()->closed_at);

        $this->actingAs($this->admin)->post(route('admin.conversas.reabrir', $conversation))->assertRedirect();
        $this->assertSame('open', $conversation->fresh()->status);
        $this->assertNull($conversation->fresh()->closed_at);
    }

    public function test_an_interest_becomes_an_order_draft(): void
    {
        $product = Product::factory()->create(['name' => 'Vaso Onda', 'personalization_fields' => [['key' => 'nome', 'label' => 'Nome']]]);
        $variant = Variant::factory()->for($product)->isDefault()->create(['price_cents' => 1500, 'sku' => 'VO-1']);

        [$conversation] = $this->conversation([
            'type' => 'interest',
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'quantity' => 2,
            'personalization' => ['nome' => 'Rita'],
            'phone' => '912345678',
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.conversas.rascunho', $conversation))
            ->assertRedirect(route('admin.encomendas.rascunhos.edit', OrderDraft::query()->firstOrFail()));

        $draft = OrderDraft::query()->firstOrFail();
        $this->assertSame($this->admin->id, $draft->created_by_user_id);
        $this->assertSame('Ana Silva', $draft->payload['customer_name']);
        $this->assertSame('ana@example.com', $draft->payload['email']);
        $this->assertSame($variant->id, $draft->payload['items'][0]['variant_id']);
        $this->assertSame(2, $draft->payload['items'][0]['qty']);
        $this->assertSame('15.00', $draft->payload['items'][0]['unit_price']);
        $this->assertStringContainsString('Rita', $draft->payload['admin_note']);
        $this->assertSame($draft->id, $conversation->fresh()->order_draft_id);

        // Segunda vez: abre o mesmo rascunho em vez de criar outro.
        $this->actingAs($this->admin)->post(route('admin.conversas.rascunho', $conversation));
        $this->assertSame(1, OrderDraft::query()->count());
    }

    public function test_the_thread_page_has_the_product_and_notes(): void
    {
        $product = Product::factory()->create(['name' => 'Vaso Onda']);
        [$conversation] = $this->conversation(['type' => 'interest', 'product_id' => $product->id]);
        app(ConversationService::class)->reply($conversation, 'Nota', $this->admin, internal: true);

        $this->actingAs($this->admin)
            ->get(route('admin.conversas.show', $conversation))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/conversas/show')
                ->where('conversation.product.name', 'Vaso Onda')
                ->has('conversation.messages', 2)
                ->where('conversation.messages.1.internal', true));
    }
}
