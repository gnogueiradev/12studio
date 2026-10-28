<x-mail::message>
# {{ __('store.mail.replied_title') }}

{{ __('store.mail.hello', ['name' => $conversation->name]) }}

{{ __('store.mail.replied_body', ['reference' => $conversation->reference()]) }}

<x-mail::button :url="$url">
{{ __('store.mail.view_reply') }}
</x-mail::button>

{{ __('store.mail.private_link') }}

{{ __('store.mail.thanks') }}<br>
{{ $brand }}
</x-mail::message>
