<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Interruptor geral
    |--------------------------------------------------------------------------
    |
    | Desligada, nada sai para a Etsy nem entra de la: o cliente HTTP recusa
    | antes de fazer o pedido, e as tarefas agendadas saem logo. Desligada por
    | omissao — cada fase da integracao pode ir para producao sem acordar
    | nada, e liga-se quando o dono quiser.
    |
    */

    'enabled' => (bool) env('ETSY_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | A app registada em etsy.com/developers
    |--------------------------------------------------------------------------
    |
    | Sao a identidade da app e nao uma preferencia, por isso vivem no .env e
    | nao no backoffice. O que o backoffice guarda (cifrado, na tabela
    | etsy_connections) sao os tokens da loja que se ligou.
    |
    | A Etsy quer os dois juntos no cabecalho x-api-key: "keystring:secret".
    |
    */

    'keystring' => env('ETSY_KEYSTRING'),

    'shared_secret' => env('ETSY_SHARED_SECRET'),

    /*
    | O endereco de retorno do OAuth. Tem de coincidir, letra a letra, com um
    | dos "Callback URLs" da app na Etsy. Vazio = o da rota admin.etsy.callback
    | com o APP_URL — que so serve se o APP_URL for mesmo o dominio publico.
    */

    'redirect_uri' => env('ETSY_REDIRECT_URI'),

    /*
    |--------------------------------------------------------------------------
    | Enderecos
    |--------------------------------------------------------------------------
    |
    | O do token esta no .env porque a especificacao e a documentacao da Etsy
    | nao concordam (openapi.etsy.com vs api.etsy.com). O da documentacao e o
    | que os exemplos oficiais usam; se um dia mudar, muda-se sem deploy.
    |
    */

    'api_url' => 'https://openapi.etsy.com/v3/application',

    'authorize_url' => 'https://www.etsy.com/oauth/connect',

    'token_url' => env('ETSY_TOKEN_URL', 'https://api.etsy.com/v3/public/oauth/token'),

    /*
    |--------------------------------------------------------------------------
    | Permissoes pedidas a loja
    |--------------------------------------------------------------------------
    |
    | Todas de uma vez, na primeira ligacao: acrescentar uma mais tarde obriga
    | o dono a religar a loja.
    |
    |   listings_*      anuncios, inventario, fotos e video
    |   shops_r/w       loja, seccoes, perfis de envio e de processamento,
    |                   politicas de devolucao
    |   transactions_*  recibos, pagamentos, extrato e marcar envios
    |   email_r         ler um utilizador (getUser)
    |   address_r       as moradas da conta ligada
    |
    */

    'scopes' => [
        'listings_r', 'listings_w', 'listings_d',
        'shops_r', 'shops_w',
        'transactions_r', 'transactions_w',
        'email_r', 'address_r',
    ],

    // Segundos. Curto de proposito: ha chamadas feitas dentro de um pedido
    // do backoffice, e a fila mata um job aos 60.
    'timeout' => 15,

    // Margem, em segundos, para renovar o token antes de ele expirar.
    'token_leeway' => 120,

];
