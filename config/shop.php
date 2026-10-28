<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Identidade da loja
    |--------------------------------------------------------------------------
    |
    | Valores estruturais da loja. Definicoes editaveis pelo admin em
    | runtime (custo do kWh, wattagem, custo/hora de trabalho, nome da loja)
    | vivem na tabela `settings`, nao aqui — isto e so o que nao muda sem
    | deploy, ou o valor por omissao enquanto ninguem lhe mexeu.
    |
    */

    // Nome e frase do rodape por omissao. Quem manda e o BrandSettings:
    // estes so valem enquanto a tabela `settings` nao tiver brand.name /
    // brand.tagline.
    'name' => env('SHOP_NAME', '12studio'),

    'tagline' => env('SHOP_TAGLINE', 'peças impressas em 3D em Portugal.'),

    'currency' => 'EUR',

    // Taxa de IVA por omissao para produtos novos (Portugal continental).
    // Cada produto guarda a sua propria vat_rate; isto e so o default.
    'default_vat_rate' => 23,

];
