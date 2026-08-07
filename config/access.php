<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Acesso publico ao site
    |--------------------------------------------------------------------------
    |
    | Enquanto nao ha nada para vender, a loja fica fechada: '/' mostra uma
    | landing "em breve" e as paginas de autenticacao deixam de existir para
    | quem nao conhece o URL secreto. Ambos os estados sao reversiveis por
    | variavel de ambiente — reabrir a loja nao precisa de deploy de codigo.
    |
    */

    // Segredo que abre as paginas de autenticacao (visitar /acesso/<segredo>
    // grava o cookie). Vazio = cadeado desligado — e assim que dev local e CI
    // correm, para nao partir os testes de auth existentes.
    'login_secret' => env('LOGIN_GATE_SECRET'),

    // Dias que o cookie de acesso dura no browser antes de ser preciso voltar
    // a visitar o URL secreto.
    'login_secret_ttl_days' => (int) env('LOGIN_GATE_TTL_DAYS', 30),

    // false = montra fechada, '/' renderiza a pagina `coming-soon`.
    // true = volta a montra normal com a grelha de produtos.
    'store_open' => (bool) env('STORE_OPEN', false),

];
