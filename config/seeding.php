<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Admin inicial
    |--------------------------------------------------------------------------
    |
    | Credenciais do administrador criado pelo DatabaseSeeder. Em producao o
    | seeder falha se a password estiver vazia — nunca existe um admin com
    | password por omissao (padrao herdado do projeto qrcode).
    |
    */

    'admin_name' => env('SEED_ADMIN_NAME', 'Admin'),

    'admin_email' => env('SEED_ADMIN_EMAIL', 'admin@12studio.test'),

    'admin_password' => env('SEED_ADMIN_PASSWORD', ''),

];
