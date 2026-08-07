<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Identidade da loja
    |--------------------------------------------------------------------------
    |
    | Valores estruturais da 12studio. Definicoes editaveis pelo admin em
    | runtime (custo do kWh, wattagem, custo/hora de trabalho) vivem na
    | tabela `settings`, nao aqui — isto e so o que nao muda sem deploy.
    |
    */

    'name' => env('SHOP_NAME', '12studio'),

    'currency' => 'EUR',

    // Taxa de IVA por omissao para produtos novos (Portugal continental).
    // Cada produto guarda a sua propria vat_rate; isto e so o default.
    'default_vat_rate' => 23,

];
