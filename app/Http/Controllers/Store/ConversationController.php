<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Conversation\OpenConversationRequest;
use App\Http\Requests\Conversation\ReplyConversationRequest;
use App\Models\Conversation;
use App\Models\ConversationAttachment;
use App\Services\ConversationService;
use App\Store\ConversationPresenter;
use App\Support\LocalizedUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * As conversas do lado do cliente, sem conta: abrir (pelos formularios da
 * montra), ver e responder pelo link privado, descarregar os anexos e pedir
 * o link outra vez.
 */
class ConversationController extends Controller
{
    public function __construct(
        private ConversationService $conversations,
    ) {}

    public function store(OpenConversationRequest $request): RedirectResponse
    {
        // Spam: a mesma resposta que um envio a serio, com um link que nao
        // leva a lado nenhum. Quem o mandou nao fica a saber que foi parado.
        if ($request->isSpam()) {
            return $this->opened(Str::random(ConversationService::TOKEN_LENGTH));
        }

        [, $token] = $this->conversations->open($request->conversation(), $request->attachments(), $request->ip());

        return $this->opened($token);
    }

    public function show(Request $request, string $token): Response
    {
        $conversation = $this->find($token);
        $this->conversations->markReadByCustomer($conversation);

        $response = Inertia::render('store/conversation', [
            'conversation' => ConversationPresenter::forCustomer($conversation, $token),
        ])->toResponse($request);

        // O link e privado: nada de motores de busca, e nada de o browser
        // o mandar como Referer para os links que a loja escreva.
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    public function reply(ReplyConversationRequest $request, string $token): RedirectResponse
    {
        $conversation = $this->find($token);

        $this->conversations->reply($conversation, (string) $request->validated('message'), files: $request->attachments());

        return back();
    }

    public function attachment(string $token, ConversationAttachment $attachment): StreamedResponse
    {
        $conversation = $this->find($token);

        abort_unless($attachment->conversation_id === $conversation->id, 404);

        return Storage::disk('local')->download($attachment->path, $attachment->original_name);
    }

    /** A pagina "Perdeste o link?". */
    public function lost(): \Inertia\Response
    {
        return Inertia::render('store/conversation-lost');
    }

    /** Responde sempre o mesmo, haja conversas com esse email ou nao. */
    public function resend(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email:rfc', 'max:255']], [], [
            'email' => trans('store.attributes.email'),
        ]);

        $this->conversations->resendLinks($data['email']);
        $this->toast(trans('store.conversation.resend_done'));

        return back();
    }

    private function find(string $token): Conversation
    {
        $conversation = $this->conversations->findByToken($token);

        abort_if($conversation === null, 404);

        return $conversation;
    }

    private function opened(string $token): RedirectResponse
    {
        Inertia::flash('conversation', ['url' => LocalizedUrl::path('/conversa/'.$token)]);

        return back();
    }
}
