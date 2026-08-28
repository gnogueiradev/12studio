<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') == 'dark']) data-appearance="{{ $appearance ?? 'system' }}">
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

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        <x-inertia::head>
            <title>{{ config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
