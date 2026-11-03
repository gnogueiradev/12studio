{{-- O layout do Laravel, com o nome da loja das definicoes (e nao o APP_NAME) e o rodape no idioma do email. --}}
@inject('brand', 'App\Services\BrandSettings')
<x-mail::layout>
    {{-- Header --}}
    <x-slot:header>
        <x-mail::header :url="config('app.url')">
            {{ $brand->name() }}
        </x-mail::header>
    </x-slot:header>

    {{-- Body --}}
    {{ $slot }}

    {{-- Subcopy --}}
    @isset($subcopy)
        <x-slot:subcopy>
            <x-mail::subcopy>
                {{ $subcopy }}
            </x-mail::subcopy>
        </x-slot:subcopy>
    @endisset

    {{-- Footer --}}
    <x-slot:footer>
        <x-mail::footer>
            © {{ date('Y') }} {{ $brand->name() }}. {{ __('store.footer.rights') }}
        </x-mail::footer>
    </x-slot:footer>
</x-mail::layout>
