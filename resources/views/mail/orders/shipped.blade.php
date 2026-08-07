<x-mail::message>
# A tua encomenda seguiu viagem

Olá {{ $order->customer_name }}, a encomenda **{{ $order->order_number }}** já saiu daqui.

@if ($order->shipping_method_name)
Envio: {{ $order->shipping_method_name }}
@endif

@if ($order->tracking_number)
Número de seguimento: **{{ $order->tracking_number }}**
@endif

@if ($order->tracking_url)
<x-mail::button :url="$order->tracking_url">
Seguir a encomenda
</x-mail::button>
@endif

Obrigado,<br>
12studio
</x-mail::message>
