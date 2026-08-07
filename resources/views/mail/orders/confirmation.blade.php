<x-mail::message>
# Obrigado pela tua encomenda

Olá {{ $order->customer_name }}, registámos a encomenda **{{ $order->order_number }}**.

<x-mail::table>
| Artigo | Qt. | Total |
|:-------|:---:|------:|
@foreach ($order->items as $item)
| {{ $item->product_name }}{{ $item->variant_label ? ' — '.$item->variant_label : '' }} | {{ $item->qty }} | {{ number_format($item->line_total_cents / 100, 2, ',', ' ') }} € |
@endforeach
</x-mail::table>

@if ($order->shipping_cents > 0)
Portes: {{ number_format($order->shipping_cents / 100, 2, ',', ' ') }} €
@endif

**Total: {{ number_format($order->total_cents / 100, 2, ',', ' ') }} €**

@if ($order->payment_status !== 'paid')
Assim que o pagamento estiver confirmado, começamos a preparar tudo.
@endif

Obrigado,<br>
12studio
</x-mail::message>
