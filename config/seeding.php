<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Admin inicial
    |--------------------------------------------------------------------------
    |
    | Credenciais do administrador criado pelo DatabaseSeeder. Em producao o
    | seeder falha se a password estiver vazia ou for curta — nunca existe um
    | admin com password por omissao (padrao herdado do projeto qrcode).
    |
    | A `admin_password` NAO tem valor por omissao, e isso e a propria guarda:
    | tinha '123', e um default e uma validacao sobre o mesmo valor sao
    | inimigos — o default consumia o estado vazio que o seeder procurava, e a
    | condicao `$password === ''` nunca chegava a ser verdadeira. A promessa
    | acima estava escrita mas nao era cumprida.
    |
    | O fallback de desenvolvimento vive no DatabaseSeeder e nao aqui, porque
    | depende do ambiente: um app()->isProduction() dentro de um ficheiro de
    | config seria avaliado UMA vez, no `config:cache` do deploy, e ficaria
    | gravado com o valor desse momento.
    |
    */

    'admin_name' => env('SEED_ADMIN_NAME', 'Admin'),

    'admin_email' => env('SEED_ADMIN_EMAIL', 'admin@12studio.test'),

    'admin_password' => env('SEED_ADMIN_PASSWORD'),

];
