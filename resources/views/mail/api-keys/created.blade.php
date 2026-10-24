<x-mail::message>
# Nova aplicação ligada à tua conta

Olá {{ $user->name }}, foi ligada à tua conta a aplicação **{{ $keyName }}**, com acesso de **{{ $accessLabel }}**@if ($expiresAt), válida até {{ $expiresAt }}@endif.

Se não foste tu, revoga-a já e muda a tua password.

<x-mail::button :url="$url">
Ver chaves e aplicações
</x-mail::button>

{{ $brand }}
</x-mail::message>
