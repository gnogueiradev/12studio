<x-mail::message>
# O teu código está quase a expirar

Depois da encomenda **{{ $orderNumber }}**, deixámos-te este código para a próxima compra. Ainda vais a tempo:

<x-mail::panel>
**{{ $promoCode->code }}**
</x-mail::panel>

Vale **{{ $promoCode->value }} %** de desconto até {{ $promoCode->expires_at?->format('d/m/Y') }}. Só funciona com este email e só uma vez.

<x-mail::button :url="config('app.url')">
Ver a loja
</x-mail::button>

Obrigado,<br>
{{ $brand }}

<x-mail::subcopy>
Recebes este email porque compraste na loja. Se não quiseres receber mais ofertas, [deixa de as receber aqui]({{ $unsubscribeUrl }}).
</x-mail::subcopy>
</x-mail::message>
