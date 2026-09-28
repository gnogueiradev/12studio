<x-mail::message>
# Nova aplicação ligada ao 12studio

Olá {{ $user->name }}, foi ligada à tua conta a aplicação **{{ $keyName }}**, com acesso de **{{ $accessLabel }}**@if ($expiresAt), válida até {{ $expiresAt }}@endif.

Se não foste tu, revoga-a já e muda a tua password.

<x-mail::button :url="$url">
Ver chaves e aplicações
</x-mail::button>

12studio
</x-mail::message>
