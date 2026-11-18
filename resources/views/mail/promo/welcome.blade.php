<x-mail::message>
# Bem-vindo à 12studio

Obrigado por teres criado conta. Para a primeira compra, tens este código:

<x-mail::panel>
**{{ $promoCode->code }}**
</x-mail::panel>

Vale **{{ number_format($promoCode->value / 100, 2, ',', ' ') }} €** de desconto, nestas condições:

- compras a partir de {{ number_format($promoCode->min_subtotal_cents / 100, 2, ',', ' ') }} €;
- válido até {{ $promoCode->expires_at?->format('d/m/Y') }};
- só funciona com este email e só uma vez;
- não acumula com outros códigos.

Obrigado,<br>
12studio
</x-mail::message>
