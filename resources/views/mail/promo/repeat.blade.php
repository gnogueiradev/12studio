<x-mail::message>
# Obrigado pela tua compra

Esperamos que estejas a gostar da encomenda **{{ $orderNumber }}**. Para a próxima, tens este código:

<x-mail::panel>
**{{ $promoCode->code }}**
</x-mail::panel>

Vale **{{ $promoCode->value }} %** de desconto, nestas condições:

- válido até {{ $promoCode->expires_at?->format('d/m/Y') }};
- só funciona com este email e só uma vez;
- não acumula com outros códigos.

<x-mail::button :url="config('app.url')">
Ver a loja
</x-mail::button>

Obrigado,<br>
{{ $brand }}

<x-mail::subcopy>
Recebes este email porque compraste na loja. Se não quiseres receber mais ofertas, [deixa de as receber aqui]({{ $unsubscribeUrl }}).
</x-mail::subcopy>
</x-mail::message>
