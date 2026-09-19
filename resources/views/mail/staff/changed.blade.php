<x-mail::message>
# {{ $change }}

Conta: **{{ $staff->name }}** ({{ $staff->email }})

Feito por: {{ $by->name }} ({{ $by->email }}), em {{ now()->format('Y-m-d H:i') }}.

Se não foste tu, desativa a conta e muda a tua password já.

<x-mail::button :url="$url">
Ver a equipa
</x-mail::button>

12studio
</x-mail::message>
