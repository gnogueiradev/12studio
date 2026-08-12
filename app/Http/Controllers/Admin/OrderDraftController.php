<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\StoreOrderDraftRequest;
use App\Models\OrderDraft;
use App\Support\ManualOrderOptions;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Encomendas manuais guardadas a meio.
 *
 * Um rascunho e o formulario, tal e qual, com o dono a espera de o acabar: nao
 * tem numero de encomenda, nao desconta stock e nao aparece em lado nenhum do
 * pipeline. So quando o admin carrega em "Criar encomenda" e que o
 * OrderController o transforma numa encomenda a serio — e o apaga.
 */
class OrderDraftController extends Controller
{
    public function index(Request $request): Response
    {
        $drafts = OrderDraft::query()
            ->where('created_by_user_id', $request->user()?->getKey())
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (OrderDraft $draft): array => [
                'id' => $draft->id,
                'customerName' => $draft->customer_name,
                'itemsCount' => count($draft->payload['items'] ?? []),
                'totalCents' => $draft->total_cents,
                'updatedAt' => $draft->updated_at?->format('Y-m-d H:i'),
            ])
            ->all();

        return Inertia::render('admin/encomendas/rascunhos/index', [
            'drafts' => $drafts,
        ]);
    }

    /**
     * Retomar: a mesma pagina do "Nova encomenda", com o formulario ja cheio.
     */
    public function edit(Request $request, OrderDraft $draft): Response
    {
        $this->authorizeOwner($request, $draft);

        return Inertia::render('admin/encomendas/create', [
            ...ManualOrderOptions::props(),
            'draft' => [
                'id' => $draft->id,
                'payload' => $draft->payload,
            ],
        ]);
    }

    public function store(StoreOrderDraftRequest $request): RedirectResponse
    {
        $payload = $request->validated();

        $draft = OrderDraft::query()->create([
            'created_by_user_id' => $request->user()?->getKey(),
            'customer_name' => $this->name($payload),
            'total_cents' => $this->total($payload),
            'payload' => $payload,
        ]);

        $this->toast('Rascunho guardado.');

        // Redireciona para o proprio rascunho em vez de voltar atras: a pagina
        // e a mesma e o conteudo e o que o admin acabou de escrever, por isso
        // no ecra nao se ve nada mudar — mas a partir daqui ha um id, e as
        // gravacoes seguintes atualizam este rascunho em vez de criarem outro.
        return to_route('admin.encomendas.rascunhos.edit', $draft);
    }

    public function update(StoreOrderDraftRequest $request, OrderDraft $draft): RedirectResponse
    {
        $this->authorizeOwner($request, $draft);

        $payload = $request->validated();

        $draft->update([
            'customer_name' => $this->name($payload),
            'total_cents' => $this->total($payload),
            'payload' => $payload,
        ]);

        $this->toast('Rascunho guardado.');

        return back();
    }

    public function destroy(Request $request, OrderDraft $draft): RedirectResponse
    {
        $this->authorizeOwner($request, $draft);

        $draft->delete();

        $this->toast('Rascunho apagado.');

        return to_route('admin.encomendas.rascunhos.index');
    }

    /**
     * Um rascunho e trabalho a meio de uma pessoa, nao estado partilhado: para
     * quem nao o escreveu, nao existe.
     */
    private function authorizeOwner(Request $request, OrderDraft $draft): void
    {
        abort_unless($draft->created_by_user_id === $request->user()?->getKey(), 404);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function name(array $payload): ?string
    {
        $name = trim((string) ($payload['customer_name'] ?? ''));

        return $name === '' ? null : $name;
    }

    /**
     * Total de vitrine para a listagem. Nao passa pelo OrderService de
     * proposito: um rascunho nao tem precos resolvidos nem stock reservado, e
     * a unica coisa em jogo aqui e a coluna "Total".
     *
     * @param  array<string, mixed>  $payload
     */
    private function total(array $payload): int
    {
        $items = 0;

        foreach ($payload['items'] ?? [] as $item) {
            $items += Money::fromDecimal((string) ($item['unit_price'] ?? '0'))
                * (int) ($item['qty'] ?? 0);
        }

        return $items + Money::fromDecimal((string) ($payload['shipping_price'] ?? '0'));
    }
}
