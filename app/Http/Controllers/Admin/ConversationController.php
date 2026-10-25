<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Conversation\ReplyConversationRequest;
use App\Models\Conversation;
use App\Models\ConversationAttachment;
use App\Services\ConversationService;
use App\Store\ConversationPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A caixa de entrada das conversas da montra (/admin/conversas).
 */
class ConversationController extends Controller
{
    public function __construct(
        private ConversationService $conversations,
    ) {}

    public function index(Request $request): Response
    {
        // Segunda barreira, por cima do middleware 'admin' das rotas.
        abort_unless($request->user()?->isAdmin(), 403);

        $status = in_array($request->query('estado'), Conversation::STATUSES, true) ? (string) $request->query('estado') : null;
        $type = in_array($request->query('tipo'), Conversation::TYPES, true) ? (string) $request->query('tipo') : null;
        $search = trim((string) $request->query('q', ''));

        $conversations = Conversation::query()
            ->when($status, fn (Builder $query) => $query->where('status', $status))
            ->when($type, fn (Builder $query) => $query->where('type', $type))
            ->when($search !== '', function (Builder $query) use ($search): void {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $query->where(fn (Builder $query) => $query
                    ->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('subject', 'like', $like));
            })
            // As que esperam pela loja primeiro; dentro delas, as mais recentes.
            ->orderByRaw("CASE status WHEN 'open' THEN 0 WHEN 'answered' THEN 1 ELSE 2 END")
            ->latest('last_message_at')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (Conversation $conversation): array => ConversationPresenter::row($conversation));

        return Inertia::render('admin/conversas/index', [
            'conversations' => $conversations,
            'filters' => ['estado' => $status, 'tipo' => $type, 'q' => $search],
            'counts' => [
                'open' => Conversation::query()->where('status', 'open')->count(),
                'answered' => Conversation::query()->where('status', 'answered')->count(),
                'closed' => Conversation::query()->where('status', 'closed')->count(),
            ],
        ]);
    }

    public function show(Request $request, Conversation $conversation): Response
    {
        abort_unless($request->user()?->isAdmin(), 403);

        $this->conversations->markRead($conversation);

        return Inertia::render('admin/conversas/show', [
            'conversation' => ConversationPresenter::forAdmin($conversation),
        ]);
    }

    public function reply(ReplyConversationRequest $request, Conversation $conversation): RedirectResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        $internal = $request->boolean('internal');

        $this->conversations->reply(
            $conversation,
            (string) $request->validated('message'),
            $request->user(),
            $request->attachments(),
            $internal,
        );

        $this->toast($internal ? 'Nota guardada.' : 'Resposta enviada. O cliente recebe um email com o link.');

        return back();
    }

    public function close(Request $request, Conversation $conversation): RedirectResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        $this->conversations->close($conversation);
        $this->toast('Conversa fechada.');

        return back();
    }

    public function reopen(Request $request, Conversation $conversation): RedirectResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        $this->conversations->reopen($conversation);
        $this->toast('Conversa reaberta.');

        return back();
    }

    public function toOrderDraft(Request $request, Conversation $conversation): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user?->isAdmin(), 403);

        $draft = $this->conversations->toOrderDraft($conversation, $user);

        return to_route('admin.encomendas.rascunhos.edit', $draft);
    }

    public function attachment(Request $request, ConversationAttachment $attachment): StreamedResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        return Storage::disk('local')->download($attachment->path, $attachment->original_name);
    }
}
