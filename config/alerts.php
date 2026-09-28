<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Webhooks do Discord (12studio)
    |--------------------------------------------------------------------------
    |
    | Um canal do servidor do Discord por familia de alertas, cada um com o
    | seu webhook (Definicoes do canal -> Integracoes -> Webhooks). Webhook
    | vazio = essa familia desligada: nada e enviado e nada rebenta. E assim
    | que os testes e o dev correm.
    |
    */

    'webhooks' => [
        'encomendas' => env('DISCORD_WEBHOOK_ENCOMENDAS'),
        'stock' => env('DISCORD_WEBHOOK_STOCK'),
        'seguranca' => env('DISCORD_WEBHOOK_SEGURANCA'),
        'sistema' => env('DISCORD_WEBHOOK_SISTEMA'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Limites do verificador (alerts:check)
    |--------------------------------------------------------------------------
    */

    // Encomenda a espera de pagamento ha mais do que isto: "parada".
    'stale_payment_days' => 3,

    // Encomenda em producao ha mais do que isto, contado desde que entrou.
    'stale_production_days' => 3,

    // Chave de API que expira dentro disto: aviso.
    'key_expiry_warning_days' => 3,

    // Enquanto o problema durar, o mesmo aviso volta ao fim disto.
    'reminder_hours' => 24,

    /*
    |--------------------------------------------------------------------------
    | Sistema
    |--------------------------------------------------------------------------
    */

    // A mesma excecao, dentro desta janela, conta mas nao volta a avisar.
    'error_window_minutes' => 10,

    // Scheduler sem bater ha mais do que isto: parado.
    'scheduler_stale_minutes' => 10,

];
