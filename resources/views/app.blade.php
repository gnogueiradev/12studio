<!DOCTYPE html>
<html lang="{{ config('locales.available.'.app()->getLocale().'.hreflang', str_replace('_', '-', app()->getLocale())) }}" @class(['dark' => ($appearance ?? 'system') == 'dark']) data-appearance="{{ $appearance ?? 'system' }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{--
            Aplica o tema escuro antes do primeiro pixel, para nao haver um
            flash de tema claro enquanto o React nao hidrata. Tem mesmo de ser
            inline: um <script src> era um pedido a espera do qual o flash
            acontecia.

            O valor vem do atributo data-appearance do <html> e NAO interpolado
            aqui dentro, por duas razoes que andam juntas:

              1. Assim o corpo do script e CONSTANTE, e o CSP pode autoriza-lo
                 por um unico hash sha256 (ver App\Http\Middleware\SecurityHeaders).
                 Interpolado, o hash mudava com o tema escolhido e era preciso
                 um por valor — e mais um sempre que aparecesse um tema novo.
              2. Um atributo HTML e o contexto para que o escape do {{ }} do
                 Blade foi desenhado. Dentro de um <script> o valor ficava numa
                 string JavaScript, onde o escape do Blade so por acaso chega
                 (o browser nao descodifica entidades em raw text, por isso nem
                 a plica fechava a string nem o </script> terminava o bloco) —
                 e o cookie `appearance` nem sequer e encriptado.

            Guardado por tests/Feature/InlineScriptPolicyTest.php.
        --}}
        <script>
            (function() {
                const appearance = document.documentElement.dataset.appearance || 'system';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: #FAF8F5;
            }

            html.dark {
                background-color: #211E1C;
            }
        </style>

        {{--
            O tema da montra (ThemeSettings): so quando o dono o mudou, e so nas
            paginas da montra — as que trazem a prop `locale`. Vem depois do
            <style> de cima e sobrepoe-lhe a cor do <html>. O CSS e montado
            pelo ThemeSettings a partir de hex validados e chaves fixas: nada
            escrito por alguem chega aqui em bruto.
        --}}
        @php($themeCss = isset($page['props']['locale']) ? app(\App\Services\ThemeSettings::class)->css() : '')
        @if ($themeCss !== '')
            <style id="store-theme">{!! $themeCss !!}</style>
        @endif

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        {{--
            SEO da montra (App\Store\Seo): so nas paginas que o trazem. O
            JSON-LD sai por @json com JSON_HEX_TAG, para um "</script>" escrito
            numa descricao nao fechar o bloco; o tipo application/ld+json nao
            e executado, por isso nao precisa de hash no CSP.
        --}}
        @isset($seo)
            @if ($seo['description'])
                <meta name="description" content="{{ $seo['description'] }}">
            @endif
            @if ($seo['robots'])
                <meta name="robots" content="{{ $seo['robots'] }}">
            @endif
            <link rel="canonical" href="{{ $seo['canonical'] }}">
            @foreach ($seo['alternates'] as $alternate)
                <link rel="alternate" hreflang="{{ $alternate['hreflang'] }}" href="{{ $alternate['href'] }}">
            @endforeach
            <meta property="og:type" content="{{ $seo['type'] }}">
            <meta property="og:title" content="{{ $seo['title'] }}">
            @if ($seo['description'])
                <meta property="og:description" content="{{ $seo['description'] }}">
            @endif
            <meta property="og:url" content="{{ $seo['canonical'] }}">
            <meta property="og:site_name" content="{{ $seo['siteName'] }}">
            <meta property="og:locale" content="{{ $seo['ogLocale'] }}">
            @if ($seo['image'])
                <meta property="og:image" content="{{ $seo['image'] }}">
                <meta name="twitter:card" content="summary_large_image">
            @else
                <meta name="twitter:card" content="summary">
            @endif
            @foreach ($seo['jsonLd'] as $schema)
                <script type="application/ld+json">@json($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)</script>
            @endforeach
        @endisset

        @fonts

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        <x-inertia::head>
            <title>{{ $page['props']['brand']['name'] ?? config('app.name') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
