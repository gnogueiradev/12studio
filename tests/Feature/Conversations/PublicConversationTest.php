<?php

namespace Tests\Feature\Conversations;

use App\Alerts\AlertChannel;
use App\Jobs\SendDiscordAlert;
use App\Mail\ConversationOpenedMail;
use App\Models\Conversation;
use App\Models\ConversationAttachment;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Services\ConversationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * As conversas do lado do cliente: abrir (contacto, interesse, peca
 * personalizada), o link privado, responder, anexos, anti-spam e limites.
 */
class PublicConversationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['access.store_open' => true]);
        Mail::fake();
        Storage::fake('local');
        RateLimiter::clear('conversations');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function form(array $overrides = []): array
    {
        return array_merge([
            'type' => 'contact',
            'name' => 'Ana Silva',
            'email' => 'ana@example.com',
            'message' => 'Olá! Fazem vasos maiores?',
            'started' => Crypt::encryptString((string) now()->subSeconds(20)->timestamp),
            'website' => '',
        ], $overrides);
    }

    private function open(array $overrides = [], string $prefix = ''): TestResponse
    {
        return $this->from($prefix.'/contacto')->post($prefix.'/conversas', $this->form($overrides));
    }

    private function binaryStl(int $triangles = 2): UploadedFile
    {
        $content = str_repeat("\0", 80).pack('V', $triangles).str_repeat("\0", 50 * $triangles);

        return UploadedFile::fake()->createWithContent('peca.stl', $content);
    }

    public function test_a_contact_opens_a_conversation_and_only_the_token_hash_is_stored(): void
    {
        $this->open()->assertRedirect('/contacto')
            ->assertSessionHas('inertia.flash_data.conversation.url', fn (string $url): bool => str_starts_with($url, '/conversa/'));

        $conversation = Conversation::query()->firstOrFail();
        $this->assertSame('contact', $conversation->type);
        $this->assertSame('open', $conversation->status);
        $this->assertSame('pt', $conversation->locale);
        $this->assertNotNull($conversation->consent_at);
        $this->assertSame('Olá! Fazem vasos maiores?', $conversation->messages()->first()->body);

        $url = session('inertia.flash_data.conversation.url');
        $token = substr($url, strlen('/conversa/'));
        $this->assertSame(48, strlen($token));
        $this->assertDatabaseMissing('conversation_tokens', ['token_hash' => $token]);
        $this->assertDatabaseHas('conversation_tokens', ['token_hash' => hash('sha256', $token)]);

        Mail::assertQueued(ConversationOpenedMail::class, fn (ConversationOpenedMail $mail): bool => $mail->hasTo('ana@example.com') && $mail->locale === 'pt');
    }

    public function test_the_conversation_takes_the_language_of_the_page(): void
    {
        $this->open(['message' => 'Hello there'], '/en')->assertRedirect('/en/contacto');

        $this->assertSame('en', Conversation::query()->firstOrFail()->locale);
        Mail::assertQueued(ConversationOpenedMail::class, fn (ConversationOpenedMail $mail): bool => $mail->locale === 'en');
    }

    public function test_a_new_conversation_alerts_discord(): void
    {
        config(['alerts.webhooks.'.AlertChannel::CONVERSATIONS => 'https://discord.test/conversas']);
        Queue::fake([SendDiscordAlert::class]);

        $this->open();

        Queue::assertPushed(SendDiscordAlert::class, fn (SendDiscordAlert $job): bool => $job->channel === AlertChannel::CONVERSATIONS);
    }

    public function test_the_page_shows_the_thread_and_is_not_indexed(): void
    {
        $this->open();
        $url = session('inertia.flash_data.conversation.url');

        $this->get($url)
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertInertia(fn (Assert $page) => $page
                ->component('store/conversation')
                ->where('conversation.status', 'open')
                ->has('conversation.messages', 1)
                ->where('conversation.messages.0.body', 'Olá! Fazem vasos maiores?')
                ->where('conversation.messages.0.mine', true));
    }

    public function test_a_wrong_token_is_not_found(): void
    {
        $this->open();

        $this->get('/conversa/'.str_repeat('x', 48))->assertNotFound();
        $this->get('/conversa/curto')->assertNotFound();
    }

    public function test_the_customer_replies_and_a_closed_conversation_reopens(): void
    {
        $this->open();
        $url = session('inertia.flash_data.conversation.url');
        $conversation = Conversation::query()->firstOrFail();
        app(ConversationService::class)->close($conversation);

        $this->post($url.'/responder', ['message' => 'Ainda estou interessada.'])->assertRedirect();

        $conversation->refresh();
        $this->assertSame('open', $conversation->status);
        $this->assertNull($conversation->admin_read_at);
        $this->assertCount(2, $conversation->messages);
    }

    public function test_the_honeypot_and_the_minimum_time_fake_a_success(): void
    {
        $this->open(['website' => 'http://spam.example'])
            ->assertRedirect()
            ->assertSessionHas('inertia.flash_data.conversation.url');

        $this->open(['started' => Crypt::encryptString((string) now()->timestamp)])
            ->assertSessionHas('inertia.flash_data.conversation.url');

        $this->open(['started' => 'lixo'])
            ->assertSessionHas('inertia.flash_data.conversation.url');

        $this->open(['message' => 'Compra já https://a.example https://b.example https://c.example https://d.example'])
            ->assertSessionHas('inertia.flash_data.conversation.url');

        $this->assertSame(0, Conversation::query()->count());
        Mail::assertNothingQueued();
    }

    public function test_a_form_left_open_too_long_asks_to_send_again(): void
    {
        $this->open(['started' => Crypt::encryptString((string) now()->subHours(3)->timestamp)])
            ->assertSessionHasErrors('started');

        $this->assertSame(0, Conversation::query()->count());
    }

    public function test_opening_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->open(['email' => "pessoa{$i}@example.com"])->assertRedirect();
        }

        $this->open(['email' => 'outra@example.com'])->assertStatus(429);
    }

    public function test_validation_messages_follow_the_language(): void
    {
        $this->from('/en/contacto')->post('/en/conversas', $this->form(['email' => 'nao-e-email']))
            ->assertSessionHasErrors(['email' => 'The email field must be a valid email address.']);
    }

    public function test_interest_keeps_the_product_variant_quantity_and_personalization(): void
    {
        $product = Product::factory()->create(['name' => 'Vaso Onda', 'personalization_fields' => [['key' => 'nome', 'label' => 'Nome']]]);
        $variant = Variant::factory()->for($product)->isDefault()->create();

        $this->open([
            'type' => 'interest',
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'quantity' => 3,
            'personalization' => ['nome' => 'Rita', 'outro' => 'ignorado'],
        ])->assertRedirect();

        $conversation = Conversation::query()->firstOrFail();
        $this->assertSame('interest', $conversation->type);
        $this->assertSame($variant->id, $conversation->variant_id);
        $this->assertSame(3, $conversation->quantity);
        $this->assertSame(['nome' => 'Rita'], $conversation->personalization);
        $this->assertStringContainsString('Vaso Onda', $conversation->subject);
    }

    public function test_a_variant_of_another_product_is_refused(): void
    {
        $a = Product::factory()->create();
        $b = Product::factory()->create();
        $variant = Variant::factory()->for($b)->isDefault()->create();

        $this->open(['type' => 'interest', 'product_id' => $a->id, 'variant_id' => $variant->id])
            ->assertSessionHasErrors('variant_id');
    }

    public function test_a_custom_piece_needs_consent_and_takes_valid_files(): void
    {
        $quote = [
            'type' => 'quote',
            'message' => 'Um suporte para o meu micro.',
            'details' => ['dimensions' => '10 x 5 cm', 'quantity' => 2, 'color' => 'Preto'],
        ];

        $this->open($quote)->assertSessionHasErrors('consent');

        $this->open($quote + [
            'consent' => '1',
            'files' => [
                $this->binaryStl(),
                UploadedFile::fake()->createWithContent('ascii.stl', "solid peca\nendsolid peca\n"),
                UploadedFile::fake()->createWithContent('modelo.3mf', "PK\x03\x04resto"),
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $conversation = Conversation::query()->firstOrFail();
        $this->assertSame('quote', $conversation->type);
        $this->assertSame('10 x 5 cm', $conversation->details['dimensions']);
        $this->assertSame(3, ConversationAttachment::query()->count());

        foreach (ConversationAttachment::all() as $attachment) {
            Storage::disk('local')->assertExists($attachment->path);
            $this->assertStringStartsWith("conversations/{$conversation->id}/", $attachment->path);
        }
    }

    public function test_svg_and_fake_stl_files_are_refused(): void
    {
        $base = ['type' => 'quote', 'consent' => '1', 'message' => 'Peça'];

        $this->open($base + ['files' => [UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>')]])
            ->assertSessionHasErrors('files.0');

        $this->open($base + ['files' => [UploadedFile::fake()->createWithContent('falso.stl', '<?php echo 1;')]])
            ->assertSessionHasErrors('files.0');

        $this->open($base + ['files' => [UploadedFile::fake()->createWithContent('falso.3mf', 'nao e zip')]])
            ->assertSessionHasErrors('files.0');

        $this->open($base + ['files' => [$this->binaryStl(), $this->binaryStl(), $this->binaryStl(), $this->binaryStl()]])
            ->assertSessionHasErrors('files');

        $this->assertSame(0, Conversation::query()->count());
    }

    public function test_attachments_download_only_with_the_token_or_as_admin(): void
    {
        $this->open(['type' => 'quote', 'consent' => '1', 'files' => [$this->binaryStl()]]);
        $url = session('inertia.flash_data.conversation.url');
        $attachment = ConversationAttachment::query()->firstOrFail();

        $this->get($url.'/anexos/'.$attachment->id)
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename=peca.stl');

        $this->get('/conversa/'.str_repeat('y', 48).'/anexos/'.$attachment->id)->assertNotFound();

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.conversas.anexo', $attachment))
            ->assertOk();

        $this->actingAs(User::factory()->create())
            ->get(route('admin.conversas.anexo', $attachment))
            ->assertForbidden();
    }

    public function test_attachments_of_internal_notes_are_not_downloadable_by_the_customer(): void
    {
        $this->open();
        $url = session('inertia.flash_data.conversation.url');
        $conversation = Conversation::query()->firstOrFail();
        $admin = User::factory()->admin()->create();

        app(ConversationService::class)->reply($conversation, 'Custos', $admin, [$this->binaryStl()], internal: true);
        $attachment = ConversationAttachment::query()->firstOrFail();

        $this->get($url.'/anexos/'.$attachment->id)->assertNotFound();
    }

    public function test_a_markdown_name_does_not_become_a_link_in_the_email(): void
    {
        $this->open(['name' => '[Confirmar pagamento](https://evil.example)']);

        Mail::assertQueued(ConversationOpenedMail::class, function (ConversationOpenedMail $mail): bool {
            $html = $mail->render();
            $this->assertStringNotContainsString('href="https://evil.example"', $html);

            return true;
        });
    }

    public function test_conversation_links_keep_working_while_the_store_is_closed(): void
    {
        $this->open();
        $url = session('inertia.flash_data.conversation.url');
        $attachmentFree = $url;

        config(['access.store_open' => false]);

        $this->get($attachmentFree)->assertOk();
        $this->post($url.'/responder', ['message' => 'Ainda ai?'])->assertRedirect();
        $this->get('/conversa')->assertOk();
        // Abrir uma conversa nova continua fechado com a montra.
        $this->post('/conversas', $this->form())->assertNotFound();
    }

    public function test_resend_link_answers_the_same_and_mails_a_working_link(): void
    {
        $this->open();
        Mail::fake();

        $this->from('/conversa')->post('/conversa/reenviar', ['email' => 'ninguem@example.com'])
            ->assertRedirect()
            ->assertSessionHas('inertia.flash_data.toast.type', 'success');
        Mail::assertNothingQueued();

        $this->from('/conversa')->post('/conversa/reenviar', ['email' => 'ANA@example.com'])
            ->assertRedirect()
            ->assertSessionHas('inertia.flash_data.toast.type', 'success');

        Mail::assertQueued(ConversationOpenedMail::class, function (ConversationOpenedMail $mail): bool {
            $this->get('/conversa/'.$mail->token)->assertOk();

            return true;
        });
    }

    public function test_conversations_left_without_activity_are_pruned_too(): void
    {
        $this->open(['type' => 'quote', 'consent' => '1', 'files' => [$this->binaryStl()]]);
        $conversation = Conversation::query()->firstOrFail();
        app(ConversationService::class)->reply($conversation, 'Respondido', User::factory()->admin()->create());
        $this->assertSame('answered', $conversation->fresh()->status);

        Carbon::setTestNow(now()->addMonths(25));
        $this->artisan('model:prune', ['--model' => [ConversationAttachment::class, Conversation::class]]);

        $this->assertNull($conversation->fresh());
    }

    public function test_retention_prunes_closed_conversations_and_their_files(): void
    {
        $this->open(['type' => 'quote', 'consent' => '1', 'files' => [$this->binaryStl()]]);
        $conversation = Conversation::query()->firstOrFail();
        $attachment = ConversationAttachment::query()->firstOrFail();
        app(ConversationService::class)->close($conversation);

        Carbon::setTestNow(now()->addMonths(7));
        $this->artisan('model:prune', ['--model' => [ConversationAttachment::class, Conversation::class]]);

        $this->assertNull($attachment->fresh());
        Storage::disk('local')->assertMissing($attachment->path);
        $this->assertNotNull($conversation->fresh());

        Carbon::setTestNow(now()->addMonths(18));
        $this->artisan('model:prune', ['--model' => [ConversationAttachment::class, Conversation::class]]);
        $this->assertNull($conversation->fresh());
    }

    public function test_the_ip_is_forgotten_after_30_days(): void
    {
        $this->open();
        $this->assertNotNull(Conversation::query()->firstOrFail()->ip_address);

        Carbon::setTestNow(now()->addDays(31));
        $this->artisan('conversations:forget-ips')->assertSuccessful();

        $this->assertNull(Conversation::query()->firstOrFail()->ip_address);
    }

    public function test_the_storefront_shares_a_form_start_stamp(): void
    {
        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('storefront.form.started', fn (string $started): bool => (int) Crypt::decryptString($started) <= now()->timestamp));
    }
}
