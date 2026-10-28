<?php

namespace Tests\Feature\Stripe;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * O Stripe de faz de conta: guarda em memoria o que se lhe cria e recusa o
 * que o Stripe a serio recusa (editar o valor de um Price, cobrar um Price
 * arquivado, expirar uma sessao que ja nao esta aberta). Um fake que aceita
 * tudo deixava passar exatamente os erros que so se veem em producao.
 *
 * O `preventStrayRequests` fecha a porta a tudo o resto: um teste nunca vai
 * bater ao Stripe a serio com a chave de quem o correu.
 */
trait FakesStripe
{
    protected const STRIPE_API = 'https://stripe.test/v1';

    /** @var array<string, array<string, array<string, mixed>>> */
    protected array $stripe = [
        'products' => [],
        'prices' => [],
        'sessions' => [],
        'coupons' => [],
        'payment_intents' => [],
    ];

    /** @var array<int, array{method: string, path: string, data: array<string, mixed>, idempotency: string|null}> */
    protected array $stripeCalls = [];

    /**
     * Montada aqui e nao escrita por extenso, para o secret scanning do GitHub
     * nao a tomar por uma chave verdadeira.
     */
    protected static function stripeSecret(): string
    {
        return 'sk_'.'test_'.str_repeat('x', 24);
    }

    protected static function stripeWebhookSecret(): string
    {
        return 'whsec_'.'segredo-de-teste-do-stripe';
    }

    protected function fakeStripe(): void
    {
        config([
            'stripe.enabled' => true,
            'stripe.secret' => self::stripeSecret(),
            'stripe.webhook_secret' => self::stripeWebhookSecret(),
            'stripe.api_url' => self::STRIPE_API,
            'stripe.api_version' => null,
        ]);

        // O SSR do Inertia tambem e um pedido HTTP (ao servidor de render), e
        // o preventStrayRequests apanha-o.
        config(['inertia.ssr.enabled' => false]);

        Http::preventStrayRequests();
        Http::fake([self::STRIPE_API.'/*' => fn (Request $request) => $this->answerStripe($request)]);
    }

    /**
     * @return array<int, array{method: string, path: string, data: array<string, mixed>, idempotency: string|null}>
     */
    protected function stripeCallsTo(string $method, string $pathPattern): array
    {
        return array_values(array_filter(
            $this->stripeCalls,
            fn (array $call): bool => $call['method'] === $method && Str::is($pathPattern, $call['path']),
        ));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function stripePaymentIntent(string $id, array $attributes = []): void
    {
        $this->stripe['payment_intents'][$id] = ['id' => $id, 'object' => 'payment_intent', ...$attributes];
    }

    private function answerStripe(Request $request): mixed
    {
        if ($request->header('Authorization')[0] !== 'Bearer '.self::stripeSecret()) {
            return $this->stripeError(401, 'Invalid API Key provided.');
        }

        $path = Str::after(Str::before($request->url(), '?'), self::STRIPE_API.'/');
        // Lido do corpo tal como chega ao Stripe: tudo texto, com os colchetes.
        parse_str($request->method() === 'GET' ? '' : $request->body(), $data);
        $idempotency = $request->header('Idempotency-Key')[0] ?? null;

        $this->stripeCalls[] = ['method' => $request->method(), 'path' => $path, 'data' => $data, 'idempotency' => $idempotency];

        return match (true) {
            $request->method() === 'POST' && $path === 'products' => $this->createStripe('products', 'prod', $data + ['active' => 'true']),
            $request->method() === 'POST' && Str::is('products/*', $path) => $this->updateStripe('products', Str::after($path, 'products/'), $data),
            $request->method() === 'POST' && $path === 'prices' => $this->createStripePrice($data),
            $request->method() === 'POST' && Str::is('prices/*', $path) => $this->updateStripePrice(Str::after($path, 'prices/'), $data),
            $request->method() === 'POST' && $path === 'coupons' => $this->createStripe('coupons', 'coupon', $data),
            $request->method() === 'POST' && $path === 'checkout/sessions' => $this->createStripeSession($data),
            $request->method() === 'POST' && Str::is('checkout/sessions/*/expire', $path) => $this->expireStripeSession(Str::between($path, 'checkout/sessions/', '/expire')),
            $request->method() === 'GET' && Str::is('checkout/sessions/*', $path) => $this->readStripe('sessions', Str::after($path, 'checkout/sessions/')),
            $request->method() === 'GET' && Str::is('payment_intents/*', $path) => $this->readStripe('payment_intents', Str::after($path, 'payment_intents/')),
            default => $this->stripeError(404, "Unrecognized request URL ({$request->method()}: /v1/{$path})."),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createStripe(string $kind, string $prefix, array $data): mixed
    {
        $id = $prefix.'_'.Str::random(14);
        $this->stripe[$kind][$id] = ['id' => $id, ...$data];

        return Http::response($this->stripe[$kind][$id]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function updateStripe(string $kind, string $id, array $data): mixed
    {
        if (! isset($this->stripe[$kind][$id])) {
            return $this->stripeError(404, "No such {$kind}: '{$id}'");
        }

        $this->stripe[$kind][$id] = [...$this->stripe[$kind][$id], ...$data];

        return Http::response($this->stripe[$kind][$id]);
    }

    private function readStripe(string $kind, string $id): mixed
    {
        return isset($this->stripe[$kind][$id])
            ? Http::response($this->stripe[$kind][$id])
            : $this->stripeError(404, "No such {$kind}: '{$id}'");
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createStripePrice(array $data): mixed
    {
        if (! isset($this->stripe['products'][$data['product'] ?? ''])) {
            return $this->stripeError(400, 'No such product.');
        }

        if (! isset($data['unit_amount'], $data['currency']) || ! ctype_digit((string) $data['unit_amount'])) {
            return $this->stripeError(400, 'Missing required param: unit_amount.');
        }

        return $this->createStripe('prices', 'price', $data + ['active' => 'true']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function updateStripePrice(string $id, array $data): mixed
    {
        // O valor de um Price e imutavel no Stripe.
        foreach (['unit_amount', 'currency', 'product'] as $immutable) {
            if (array_key_exists($immutable, $data)) {
                return $this->stripeError(400, "Received unknown parameter: {$immutable}");
            }
        }

        return $this->updateStripe('prices', $id, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createStripeSession(array $data): mixed
    {
        if (($data['mode'] ?? null) !== 'payment' || empty($data['line_items']) || empty($data['success_url'])) {
            return $this->stripeError(400, 'Missing required param: mode, line_items or success_url.');
        }

        $total = 0;

        foreach ($data['line_items'] as $item) {
            $qty = (int) ($item['quantity'] ?? 0);

            if ($qty < 1) {
                return $this->stripeError(400, 'Invalid quantity.');
            }

            if (isset($item['price'])) {
                $price = $this->stripe['prices'][$item['price']] ?? null;

                if ($price === null || $price['active'] !== 'true') {
                    return $this->stripeError(400, "The price specified is inactive or does not exist: {$item['price']}");
                }

                $total += (int) $price['unit_amount'] * $qty;

                continue;
            }

            $priceData = $item['price_data'] ?? [];

            if (isset($priceData['product'])) {
                $product = $this->stripe['products'][$priceData['product']] ?? null;

                if ($product === null || $product['active'] !== 'true') {
                    return $this->stripeError(400, 'The product specified is inactive or does not exist.');
                }
            } elseif (empty($priceData['product_data']['name'])) {
                return $this->stripeError(400, 'Missing required param: price_data[product_data][name].');
            }

            $total += (int) $priceData['unit_amount'] * $qty;
        }

        $total += (int) ($data['shipping_options'][0]['shipping_rate_data']['fixed_amount']['amount'] ?? 0);

        foreach ($data['discounts'] ?? [] as $discount) {
            $total -= (int) ($this->stripe['coupons'][$discount['coupon']]['amount_off'] ?? 0);
        }

        $id = 'cs_test_'.Str::random(20);
        $this->stripe['sessions'][$id] = [
            'id' => $id,
            'object' => 'checkout.session',
            'url' => 'https://checkout.stripe.test/c/pay/'.$id,
            'status' => 'open',
            'payment_status' => 'unpaid',
            'amount_total' => $total,
            'client_reference_id' => $data['client_reference_id'] ?? null,
            'metadata' => $data['metadata'] ?? [],
            'payment_intent' => null,
            'request' => $data,
        ];

        return Http::response($this->stripe['sessions'][$id]);
    }

    private function expireStripeSession(string $id): mixed
    {
        $session = $this->stripe['sessions'][$id] ?? null;

        if ($session === null) {
            return $this->stripeError(404, "No such checkout.session: '{$id}'");
        }

        if ($session['status'] !== 'open') {
            return $this->stripeError(400, 'Only Checkout Sessions with a status of open can be expired.');
        }

        $this->stripe['sessions'][$id]['status'] = 'expired';

        return Http::response($this->stripe['sessions'][$id]);
    }

    private function stripeError(int $status, string $message): mixed
    {
        return Http::response(['error' => ['message' => $message, 'type' => 'invalid_request_error']], $status);
    }
}
