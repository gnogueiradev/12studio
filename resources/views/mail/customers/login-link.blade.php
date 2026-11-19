<x-mail::message>
# Entrar na tua conta

Pediste para entrar na tua conta da {{ $brand }}. Carrega no botão e, na página que abrir, em "Entrar".

<x-mail::button :url="$url">
Entrar na conta
</x-mail::button>

O link vale 20 minutos e só funciona uma vez. Não o partilhes: quem o tiver entra na tua conta.

Se não foste tu a pedir, ignora este email. Ninguém entra sem o link.

Obrigado,<br>
{{ $brand }}
</x-mail::message>
