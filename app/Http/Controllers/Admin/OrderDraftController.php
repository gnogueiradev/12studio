<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminActionRequest;
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
                'payload' => $this->formShape($draft->payload),
            ],
        ]);
    }

    public function store(StoreOrderDraftRequest $request): RedirectResponse
    {
        $payload = $this->formShape($request->validated());

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

        $payload = $this->formShape($request->validated());

        $draft->update([
            'customer_name' => $this->name($payload),
            'total_cents' => $this->total($payload),
            'payload' => $payload,
        ]);

        $this->toast('Rascunho guardado.');

        return back();
    }

    /**
     * O AdminActionRequest e um Request com a verificacao de administrador
     * agarrada; o authorizeOwner por baixo continua a ser preciso, porque ser
     * administrador nao da acesso ao rascunho a meio de outra pessoa.
     */
    public function destroy(AdminActionRequest $request, OrderDraft $draft): RedirectResponse
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
     * O payload promete ser "o formulario, tal e qual" — e ha uma coisa no
     * caminho a desmentir a promessa. O formulario manda `''` nos campos por
     * preencher; o ConvertEmptyStringsToNull do Laravel, que e global e corre
     * ANTES da validacao, troca-os todos por null. Como aqui tudo e
     * `nullable`, os null passam e vao para a coluna JSON.
     *
     * Ao retomar, a pagina faz `{...BLANK, ...payload}`: um spread tapa um
     * campo em FALTA, mas nao um campo presente a null — o null ganha ao `''`
     * do BLANK, e o primeiro `.trim()` do render deitava a pagina abaixo.
     *
     * Corre a gravar E a ler: a gravar para nao entrarem mais null, a ler
     * porque os rascunhos guardados antes disto continuam com os null na base
     * de dados e tem de abrir na mesma.
     *
     * A lista e a dos campos que NAO sao texto, e nao ao contrario: assim um
     * campo novo no formulario fica coberto sem ninguem se lembrar dele.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function formShape(array $payload): array
    {
        $keepNull = ['user_id', 'draft_id', 'send_confirmation'];
        $keepNullInItem = ['variant_id', 'qty', 'vat_rate'];

        foreach ($payload as $key => $value) {
            if ($value === null && ! in_array($key, $keepNull, true)) {
                $payload[$key] = '';
            }
        }

        foreach ($payload['items'] ?? [] as $line => $item) {
            foreach ($item as $key => $value) {
                if ($value === null && ! in_array($key, $keepNullInItem, true)) {
                    $payload['items'][$line][$key] = '';
                }
            }
        }

        return $payload;
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
