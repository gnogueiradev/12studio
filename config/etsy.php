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
    |--------------------------------------------------------------------------
    | Avisos da Etsy (webhooks)
    |--------------------------------------------------------------------------
    |
    | Opcional. Com ele, uma venda entra em segundos em vez de esperar ate 5
    | minutos pela importacao seguinte. O segredo e o que a Etsy mostra no
    | portal de webhooks da app, ao criar o endpoint (comeca por "whsec_");
    | sem ele a porta dos avisos nao existe.
    |
    | A tolerancia, em segundos, e ate que idade se aceita um aviso assinado:
    | e o que impede reenviar mais tarde um aviso verdadeiro.
    |
    */

    'webhook_secret' => env('ETSY_WEBHOOK_SECRET'),

    'webhook_tolerance' => 300,

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

    // Quanto se espera, em segundos, pela renovacao que outro processo ja
    // esta a fazer, antes de desistir com um erro que se sabe mostrar.
    'token_lock_wait' => 15,

    /*
    |--------------------------------------------------------------------------
    | Anuncios
    |--------------------------------------------------------------------------
    |
    | Os limites sao os da Etsy. Ficam aqui, e nao espalhados pelo codigo,
    | porque ela muda-os de vez em quando — e porque o `when_made` muda todos
    | os anos (o intervalo mais recente era `2020_2026` em outubro de 2026).
    |
    */

    'listing' => [
        'title_max' => 140,
        'tags_max' => 13,
        'tag_length_max' => 20,
        'materials_max' => 13,
        'images_max' => 20,
        'quantity_max' => 999,
        // Megabytes. O limite da Etsy para o video de um anuncio.
        'video_max_mb' => 100,
        'who_made' => ['i_did', 'someone_else', 'collective'],
        'when_made' => [
            'made_to_order', '2020_2026', '2010_2019', '2007_2009', 'before_2007',
            '2000_2006', '1990s', '1980s', '1970s', '1960s', '1950s', '1940s',
            '1930s', '1920s', '1910s', '1900s', '1800s', '1700s', 'before_1700',
        ],
    ],

    /*
    | As variacoes de um anuncio. Cor, material e tamanho nao existem na Etsy
    | como propriedades com nome: usam-se as "propriedades personalizadas",
    | que tem estes ids fixos e levam o nome que se lhes der.
    |
    | POR CONFIRMAR com um rascunho de teste: o terceiro id (516) vem do
    | tutorial de anuncios da Etsy e nunca foi experimentado daqui. Com duas
    | dimensoes a variar so se usam os dois primeiros.
    */

    'variations' => [
        'property_ids' => [513, 514, 516],
        'labels' => ['color' => 'Cor', 'material' => 'Material', 'size' => 'Tamanho'],
    ],

    /*
    | Taxas da Etsy, para a vista de margens. Sao uma ESTIMATIVA de partida —
    | o dono acerta-as em Etsy -> Precos com o que vir na conta dele, e a
    | partir da fase das encomendas a margem passa a usar as taxas reais de
    | cada venda.
    |
    |   percentagem: 6,5 % de transacao + 4 % de processamento do pagamento
    |   fixa:        0,30 EUR do pagamento + ~0,18 EUR de publicacao (0,20 USD)
    */

    'fees' => [
        'percentage_bp' => 1050,
        'fixed_cents' => 48,
    ],

    // Quantas unidades anuncia um produto feito por encomenda: nao ha
    // prateleira a esvaziar, mas a Etsy quer um numero.
    'mto_quantity' => 10,

    /*
    | A importacao de encomendas. 100 e o maximo que a Etsy da por pagina.
    */

    'orders' => [
        'page_size' => 100,
    ],

];
