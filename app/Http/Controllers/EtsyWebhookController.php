<?php

namespace App\Http\Controllers;

use App\Etsy\EtsyTokens;
use App\Etsy\EtsyWebhookSignature;
use App\Jobs\ImportEtsyOrders;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Os avisos da Etsy: "houve uma venda", "foi cancelada", "foi enviada".
 *
 * Do aviso aproveita-se uma so coisa: que ha novidades. Nao se le dele a
 * encomenda nem se vai buscar o endereco que ele traz — manda-se correr a
 * importacao de sempre, que pergunta a Etsy pelo que mudou, com o nosso token
 * e para a nossa loja. Assim um aviso, mesmo verdadeiro, nunca consegue por
 * ca nada que a Etsy nao confirme a seguir.
 *
 * E so um acelerador: sem ele as encomendas entram na mesma, de 5 em 5
 * minutos. Por isso responde depressa e nunca falha por causa da importacao,
 * que vai para a fila.
 */
class EtsyWebhookController extends Controller
{
    /** Os avisos que pedem uma importacao. Os de anuncios e inventario nao. */
    public const EVENTS = ['order.paid', 'order.canceled', 'order.shipped', 'order.delivered'];

    public function __invoke(Request $request, EtsyWebhookSignature $signature, EtsyTokens $tokens): Response
    {
        // Desligada ou sem segredo: a porta nao existe. Um 404, e nao um
        // 401, para nao dizer a quem sonda que ha aqui alguma coisa.
        if (! $tokens->isEnabled() || ! $signature->isConfigured()) {
            abort(404);
        }

        $id = $request->header('webhook-id');

        if (! $signature->isValid(
            $request->getContent(),
            is_string($id) ? $id : null,
            $this->header($request, 'webhook-timestamp'),
            $this->header($request, 'webhook-signature'),
        )) {
            // Sem o corpo nem os cabecalhos: sao de quem tentou.
            Log::warning('Aviso da Etsy recusado: assinatura invalida ou fora de prazo.', ['ip' => $request->ip()]);

            abort(401);
        }

        // A partir daqui o aviso e da Etsy, e responde-se sempre que sim:
        // um "nao" faria a Etsy repeti-lo durante um dia.

        // O mesmo aviso outra vez (ela repete quando acha que nao chegou).
        if (! Cache::add('etsy:webhook:'.sha1((string) $id), true, now()->addDay())) {
            return response()->noContent();
        }

        $event = json_decode($request->getContent(), true);

        if (! is_array($event)
            || ! in_array($event['event_type'] ?? null, self::EVENTS, true)
            || (string) ($event['shop_id'] ?? '') !== (string) $tokens->connection()?->shop_id) {
            return response()->noContent();
        }

        ImportEtsyOrders::dispatch();

        return response()->noContent();
    }

    private function header(Request $request, string $name): ?string
    {
        $value = $request->header($name);

        return is_string($value) ? $value : null;
    }
}
