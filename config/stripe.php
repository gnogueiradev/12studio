<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Interruptor geral
    |--------------------------------------------------------------------------
    |
    | Desligado, nada sai para o Stripe nem entra de la: o cliente HTTP recusa
    | antes de fazer o pedido, o link de pagamento e o webhook dao 404 e os
    | produtos nao se sincronizam. Desligado por omissao, como a Etsy.
    |
    */

    'enabled' => (bool) env('STRIPE_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Chaves
    |--------------------------------------------------------------------------
    |
    | A chave secreta da conta (sk_test_… para ensaiar, sk_live_… a serio) e o
    | segredo do endpoint de webhooks, que o Dashboard mostra ao cria-lo
    | (whsec_…). Sao a identidade da loja no Stripe: vivem no .env, nunca no
    | backoffice.
    |
    */

    'secret' => env('STRIPE_SECRET'),

    'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),

    // Ate que idade, em segundos, se aceita um aviso assinado. E o que impede
    // reenviar mais tarde um aviso verdadeiro. 300 e o que o Stripe recomenda.
    'webhook_tolerance' => 300,

    /*
    | Vazio = a versao da API fixada na conta, no Dashboard. Fixa-la aqui so
    | quando se quiser mudar de versao sem mexer na conta.
    */

    'api_version' => env('STRIPE_API_VERSION'),

    'api_url' => 'https://api.stripe.com/v1',

    // Segundos. Ha chamadas feitas a meio de um pedido (o botao "Pagar").
    'timeout' => 15,

    'currency' => 'eur',

];
