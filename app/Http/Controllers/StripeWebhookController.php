<?php

namespace App\Http\Controllers;

use App\Models\WebhookEvent;
use App\Stripe\StripeClient;
use App\Stripe\StripeEvents;
use App\Stripe\StripeWebhookSignature;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Os avisos do Stripe: "a encomenda foi paga", "o Multibanco foi pago",
 * "foi reembolsada".
 *
 * Ao contrario da Etsy, o aviso e lido: o corpo assinado e a fonte de
 * verdade que o Stripe documenta, e e o que permite marcar a encomenda paga
 * no segundo em que o cliente paga.
 *
 * Cada aviso e tratado uma vez. O id dele entra em webhook_events na MESMA
 * transacao em que e tratado: se ja la esta, e uma repeticao e responde-se
 * que sim sem fazer nada; se o tratamento falha, a transacao desfaz-se, o
 * Stripe recebe um 500 e repete mais tarde.
 */
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, StripeClient $stripe, StripeWebhookSignature $signature, StripeEvents $events): Response
    {
        // Desligado ou sem segredo: a porta nao existe. Um 404, e nao um 401,
        // para nao dizer a quem sonda que ha aqui alguma coisa.
        if (! $stripe->isEnabled() || ! $signature->isConfigured()) {
            abort(404);
        }

        $header = $request->header('Stripe-Signature');

        if (! $signature->isValid($request->getContent(), is_string($header) ? $header : null)) {
            // Uma linha por minuto por origem — quem insiste nao enche o log.
            if (Cache::add('stripe:webhook:refused:'.sha1((string) $request->ip()), true, 60)) {
                Log::warning('Aviso do Stripe recusado: assinatura invalida ou fora de prazo.', ['ip' => $request->ip()]);
            }

            abort(401);
        }

        $event = json_decode($request->getContent(), true);

        if (! is_array($event) || ! is_string($event['id'] ?? null) || ! is_string($event['type'] ?? null)) {
            return response()->noContent();
        }

        DB::transaction(function () use ($event, $events): void {
            if (WebhookEvent::query()->whereKey($event['id'])->exists()) {
                return;
            }

            WebhookEvent::query()->create([
                'id' => substr($event['id'], 0, 60),
                'type' => substr($event['type'], 0, 60),
            ]);

            $events->handle($event);

            WebhookEvent::query()->whereKey($event['id'])->update(['processed_at' => now()]);
        });

        return response()->noContent();
    }
}
