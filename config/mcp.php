<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Interruptor (12studio)
    |--------------------------------------------------------------------------
    |
    | MCP_ENABLED=false faz o /mcp responder 503 a toda a gente, sem mexer em
    | chaves nem em codigo. E o travao de emergencia: uma chave exposta e sem
    | tempo para descobrir qual — desliga-se aqui e investiga-se depois.
    |
    */

    'enabled' => (bool) env('MCP_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Imagens de produto (12studio)
    |--------------------------------------------------------------------------
    |
    | Limites do product_image_add. Nao sao os da galeria do backoffice (5 MB,
    | sem minimo de dimensoes): esses vivem no StoreProductImageRequest. Os
    | megapixeis travam imagens que pesam pouco e descomprimem para muito.
    |
    */

    'images' => [
        'max_bytes' => (int) env('MCP_IMAGE_MAX_BYTES', 8 * 1024 * 1024),
        'min_side' => (int) env('MCP_IMAGE_MIN_SIDE', 600),
        'max_megapixels' => (float) env('MCP_IMAGE_MAX_MEGAPIXELS', 50),
    ],

    /*
    |--------------------------------------------------------------------------
    | Redirect Domains
    |--------------------------------------------------------------------------
    |
    | These domains are the domains that OAuth clients are permitted to use
    | for redirect URIs. Each domain should be specified with its scheme
    | and host. Domains not in this list will raise validation errors.
    |
    | An "*" may be used to allow all domains.
    |
    */

    // 12studio: NUNCA '*'. Com o registo dinamico de clientes aberto, '*'
    // deixava qualquer site registar-se como "Claude" e usar o nosso ecra de
    // autorizacao para pescar um token para um redirect seu. So o Claude.
    'redirect_domains' => [
        'https://claude.ai',
        'https://claude.com',
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed Custom Schemes
    |--------------------------------------------------------------------------
    |
    | Native desktop OAuth clients like Cursor and VS Code use private-use URI
    | schemes (RFC 8252) for redirect callbacks instead of standard schemes
    | like HTTPS. Here, you may list which custom schemes you will allow.
    |
    */

    'custom_schemes' => [
        // 'claude',
        // 'cursor',
        // 'vscode',
    ],

    /*
    |--------------------------------------------------------------------------
    | Authorization Server
    |--------------------------------------------------------------------------
    |
    | Here you may configure the OAuth authorization server issuer identifier
    | per RFC 8414. This value appears in your protected resource and auth
    | server metadata endpoints. When null, this defaults to `url('/')`.
    |
    */

    'authorization_server' => null,

    /*
    |--------------------------------------------------------------------------
    | Tool Search
    |--------------------------------------------------------------------------
    |
    | Here you may configure the limits enforced during tool search. The max
    | number of tool calls limits how many tools search requests can call
    | while the maximum output bytes value will limit the result sizes.
    |
    */

    'tool_search' => [
        'max_tool_calls' => 10,
        'max_output_bytes' => 65_536,
    ],

];
