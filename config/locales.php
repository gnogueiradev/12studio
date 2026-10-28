<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Idiomas da montra publica
    |--------------------------------------------------------------------------
    |
    | O idioma por omissao vive na raiz ('/produtos'); os outros com prefixo
    | ('/en/produtos'). A lista e a mesma para todas as lojas — quais estao
    | ligados em cada uma decide-se em Definicoes -> Montra (StoreSettings).
    | O backoffice fica sempre em portugues.
    |
    | Por idioma:
    |   name     - como o idioma se chama a si proprio, no seletor
    |   hreflang - o <html lang> e as alternates do SEO
    |   intl     - a localizacao do Intl.NumberFormat/DateTimeFormat
    |
    */

    'default' => 'pt',

    'available' => [
        'pt' => ['name' => 'Português', 'hreflang' => 'pt-PT', 'intl' => 'pt-PT'],
        'es' => ['name' => 'Español', 'hreflang' => 'es', 'intl' => 'es-ES'],
        'en' => ['name' => 'English', 'hreflang' => 'en', 'intl' => 'en-IE'],
        'fr' => ['name' => 'Français', 'hreflang' => 'fr', 'intl' => 'fr-FR'],
    ],

];
