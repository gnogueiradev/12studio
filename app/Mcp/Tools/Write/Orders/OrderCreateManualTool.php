<?php

namespace App\Mcp\Tools\Write\Orders;

use App\Http\Requests\Order\StoreManualOrderRequest;
use App\Mail\OrderConfirmationMail;
use App\Mcp\FormRequestRunner;
use App\Mcp\Presenters\OrderPresenter;
use App\Mcp\Tools\WriteTool;
use App\Models\Order;
use App\Models\User;
use App\Models\Variant;
use App\Services\OrderService;
use App\Support\Money;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Mail;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use RuntimeException;

#[Name('order_create_manual')]
#[Title('Registar encomenda manual')]
#[Description('Regista uma venda feita fora da loja online (Vinted, Instagram ou em mão). Cada artigo é uma variante (variant_id) e uma quantidade; o preço é o do catálogo, a menos que indiques outro com o motivo. Os artigos em stock descontam o stock logo. O email do cliente é obrigatório fora das vendas em mão. Por omissão NÃO envia email ao cliente (send_confirmation: true para enviar). A resposta mostra os contactos mascarados.')]
#[IsDestructive(false)]
class OrderCreateManualTool extends WriteTool
{
    /** Campos do pedido do backoffice que a ferramenta aceita. */
    private const FIELDS = [
        'customer_name', 'email', 'phone', 'nif', 'sales_channel',
        'external_order_reference', 'payment_method', 'payment_status',
        'shipping_price', 'shipping_method_name', 'line1', 'line2',
        'postal_code', 'city', 'country', 'admin_note', 'items',
    ];

    public function __construct(
        private FormRequestRunner $runner,
        private OrderService $orders,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'customer_name' => $schema->string()->max(120)->required(),
            'email' => $schema->string()->max(255)->description('Obrigatório exceto no canal "manual" (venda em mão).'),
            'phone' => $schema->string()->max(30),
            'nif' => $schema->string()->max(20),
            'sales_channel' => $schema->string()->enum(Order::SALES_CHANNELS)->required(),
            'external_order_reference' => $schema->string()->max(100)->description('Referência no canal (ex. número da venda na Vinted).'),
            'payment_method' => $schema->string()->enum(Order::PAYMENT_METHODS)->required(),
            'payment_status' => $schema->string()->enum(['pending', 'paid'])->description('pending (por omissão) ou paid.'),
            'shipping_price' => $schema->string()->description('Portes em euros (por omissão "0").'),
            'shipping_method_name' => $schema->string()->max(120),
            'line1' => $schema->string()->max(190)->description('Morada de envio.'),
            'line2' => $schema->string()->max(190),
            'postal_code' => $schema->string()->description('1234-567'),
            'city' => $schema->string()->max(80),
            'country' => $schema->string()->description('Código de 2 letras (PT por omissão).'),
            'admin_note' => $schema->string()->max(2000)->description('Nota interna.'),
            'items' => $schema->array()->min(1)->items($schema->object([
                'variant_id' => $schema->integer()->required(),
                'qty' => $schema->integer()->min(1)->max(999)->required(),
                'unit_price' => $schema->string()->description('Só se for diferente do catálogo (em euros).'),
                'price_override_reason' => $schema->string()->max(200)->description('Obrigatório quando o preço difere do catálogo.'),
            ]))->required(),
            'send_confirmation' => $schema->boolean()->description('Enviar email de confirmação ao cliente (por omissão, não).'),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $input = [
            'payment_status' => 'pending',
            'shipping_price' => '0',
            ...Arr::only($request->all(), self::FIELDS),
        ];

        $input['items'] = $this->withCatalogPrices((array) ($input['items'] ?? []));

        $data = $this->runner->validate(StoreManualOrderRequest::class, $input, $user);

        try {
            $order = $this->orders->createManual($data, $user);
        } catch (RuntimeException $exception) {
            return Response::error($exception->getMessage().' Nada foi registado.');
        }

        $sent = false;

        if ($request->boolean('send_confirmation') && $order->email !== null) {
            Mail::to($order->email)->send(new OrderConfirmationMail($order));
            $sent = true;
        }

        $order->load(['items', 'statusHistories', 'tags']);

        $this->changes = [
            'created' => [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'total_cents' => $order->total_cents,
                'confirmation_sent' => $sent,
            ],
        ];

        return Response::structured([
            'message' => "Encomenda {$order->order_number} registada.".($sent ? ' Email de confirmação enviado ao cliente.' : ''),
            'order' => OrderPresenter::detail($order),
        ]);
    }

    /**
     * Sem preco indicado, o artigo leva o preco de catalogo da variante (o que
     * o cliente paga agora). Com preco diferente, o StoreManualOrderRequest
     * exige o motivo — a mesma regra do formulario.
     *
     * @param  array<int, mixed>  $items
     * @return array<int, mixed>
     */
    private function withCatalogPrices(array $items): array
    {
        $ids = collect($items)->pluck('variant_id')->filter()->map(fn (mixed $id): int => (int) $id)->all();
        $prices = Variant::query()->whereKey($ids)->pluck('price_cents', 'id');

        return array_map(function (mixed $item) use ($prices): mixed {
            if (! is_array($item)) {
                return $item;
            }

            $item = Arr::only($item, ['variant_id', 'qty', 'unit_price', 'price_override_reason']);

            if (! isset($item['unit_price']) && isset($prices[(int) ($item['variant_id'] ?? 0)])) {
                $item['unit_price'] = Money::toDecimal((int) $prices[(int) $item['variant_id']]);
            }

            return $item;
        }, $items);
    }
}
