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
    //
    // E uma estimativa deliberadamente baixa: a hora de maquina nao e mao de
    // obra, e cobra-la cara castigava exatamente as pecas pequenas que demoram
    // muito tempo — o oposto do que este negocio precisa de vender.
    'machine_hourly_rate_cents' => 20,

    // Reserva estatistica para impressoes falhadas, em pontos base (500 = 5%).
    // Aplica-se so sobre filamento + maquina; ver o PricingCalculator.
    'failure_reserve_bp' => 500,

    // Chao do preco de revenda, para objetos muito pequenos nao irem a zero.
    // E ELE que protege as pecas baratas — abaixo de ~0,88 EUR de custo real e
    // este numero, e nao o multiplicador, que decide o preco.
    'minimum_resale_price_cents' => 150,

    /*
     * Margem do produtor: um multiplicador unico sobre o custo real.
     *
     * Aqui houve uma tabela progressiva (2,00x ate 2 EUR de custo, 1,90x ate 5
     * EUR, e por ai fora). Saiu por duas razoes: fazia o preco saltar de forma
     * descontinua ao atravessar um limiar — dois centimos de custo a mais
     * podiam BAIXAR o preco de venda — e empilhava-se sobre um custo de maquina
     * que ja era alto, dando precos de revenda que nao competiam com ninguem.
     *
     * As pecas pequenas ficam protegidas pelo minimum_resale_price_cents.
     */
    'resale_multiplier_bp' => 17_000,

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
     * Valor unico, e nao uma tabela por peso: o que cresce com o tamanho da
     * peca e o tempo de impressao, que ja se paga a parte. Tirar da mesa e
     * ensacar uma peca de 400 g nao demora quatro vezes o que demora uma de
     * 100 g.
     */
    'handling_cost_cents' => 15,

    // Em lote o custo fixo por peca nao se usa: o trabalho decompoe-se no que
    // se faz UMA vez (montar a mesa, tirar a placa) e no que se faz a CADA peca
    // (rebarbar, ensacar). Ver PricingCalculator::handling().
    'batch_job_handling_cents' => 20,
    'batch_unit_handling_cents' => 10,

];
