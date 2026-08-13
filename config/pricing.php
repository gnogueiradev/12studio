<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Calculadora de precos 3D
    |--------------------------------------------------------------------------
    |
    | Valores POR OMISSAO da calculadora. Cada um destes e sobreposto pela
    | chave `pricing.*` correspondente na tabela `settings` assim que o admin
    | lhe mexer em /admin/definicoes — o App\Services\PricingSettings faz a
    | fusao, e este ficheiro deixa de ser a autoridade sem deixar de ser o
    | ponto de partida (mesmo padrao do config/shop.php e da moeda).
    |
    | O que NAO esta aqui, de proposito: o degrau de 0,50 EUR do preco de
    | revenda e as tres faixas de arredondamento do preco ao cliente (0,50 /
    | 1 / 5 EUR nos limiares de 20 e 50 EUR). Sao regras comerciais fixas —
    | ninguem anuncia uma peca a 63,40 EUR — e vivem no PricingCalculator.
    |
    */

    // Custo/hora da maquina quando nao ha nenhum perfil de impressora ativo.
    // Nao e so eletricidade: cobre desgaste, correias, rolamentos, hotend,
    // nozzle, mesa, manutencao, consumiveis, depreciacao e o simples facto de
    // a impressora estar ocupada. Por isso energia e desgaste NAO se somam
    // outra vez noutra parcela.
    'machine_hourly_rate_cents' => 50,

    // Reserva estatistica para impressoes falhadas, em pontos base (800 = 8%).
    // Aplica-se so sobre filamento + maquina; ver o PricingCalculator.
    'failure_reserve_bp' => 800,

    // Chao do preco de revenda, para objetos muito pequenos nao irem a zero.
    'minimum_resale_price_cents' => 150,

    /*
     * Margem progressiva: quanto menor o custo de producao, maior a margem
     * relativa. Sem isto, uma peca de 0,80 EUR de custo vendia-se com um lucro
     * que nao paga o tempo de a embalar.
     *
     * A faixa aberta (up_to_cents = null) e SEMPRE a ultima — o
     * UpdatePricingSettingsRequest valida essa ordem.
     */
    'resale_multipliers' => [
        ['up_to_cents' => 200, 'bp' => 20_000],
        ['up_to_cents' => 500, 'bp' => 19_000],
        ['up_to_cents' => 1_000, 'bp' => 18_000],
        ['up_to_cents' => null, 'bp' => 17_000],
    ],

    // Margem do revendedor sobre o preco de revenda.
    'retail_multiplier_bp' => 17_500,

    // Chao da margem do revendedor DEPOIS do arredondamento comercial: o
    // arredondamento tanto sobe como desce, e uma descida podia deixar quem
    // revende com menos de 60% de markup.
    'minimum_retail_multiplier_bp' => 16_000,

    /*
     * Manuseamento por peca: preparar o ficheiro, enviar, tirar da mesa,
     * verificar, limpar defeitos, separar brim e suportes, embalar. Trabalho
     * que existe independentemente das horas da maquina.
     *
     * Faixa aberta (up_to_grams = null) SEMPRE em ultimo.
     */
    'handling_tiers' => [
        ['up_to_grams' => 50, 'cents' => 25],
        ['up_to_grams' => 150, 'cents' => 35],
        ['up_to_grams' => 300, 'cents' => 50],
        ['up_to_grams' => 500, 'cents' => 75],
        ['up_to_grams' => null, 'cents' => 100],
    ],

    // Em lote a tabela por peso nao se usa: o trabalho decompoe-se no que se
    // faz UMA vez (montar a mesa, tirar a placa) e no que se faz a CADA peca
    // (rebarbar, ensacar). Ver PricingCalculator::handling().
    'batch_job_handling_cents' => 20,
    'batch_unit_handling_cents' => 10,

];
