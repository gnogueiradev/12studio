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

    // Portes nacionais em centimos. Gratis quando o subtotal ANTES do desconto
    // atinge o limite.
    'shipping' => [
        'national_cents' => (int) env('SHOP_SHIPPING_NATIONAL_CENTS', 450),
        'free_above_cents' => (int) env('SHOP_SHIPPING_FREE_ABOVE_CENTS', 2500),
    ],

    // O codigo pessoal de quem cria conta.
    'welcome_code' => [
        'value_cents' => (int) env('SHOP_WELCOME_CODE_CENTS', 500),
        'min_subtotal_cents' => (int) env('SHOP_WELCOME_CODE_MIN_CENTS', 2000),
        'valid_days' => (int) env('SHOP_WELCOME_CODE_DAYS', 30),
    ],

];
