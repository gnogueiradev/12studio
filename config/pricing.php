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
    | O que NAO esta aqui, de proposito:
    |
    |   1. os numeros da MAQUINA (potencia, preco, vida util, manutencao). Sao
    |      propriedades de cada impressora e vivem em /admin/impressoras — duas
    |      maquinas nao custam o mesmo por hora. O que esta aqui em baixo, nos
    |      `fallback_printer_*`, e so a rede de seguranca para quando nao ha
    |      nenhuma impressora ativa;
    |
    |   2. o degrau de 0,50 EUR do preco de revenda e as tres faixas de
    |      arredondamento do preco ao cliente (0,50 / 1 / 5 EUR nos limiares de
    |      20 e 50 EUR). Sao regras comerciais fixas — ninguem anuncia uma peca
    |      a 63,40 EUR — e vivem no PricingCalculator.
    |
    | Unidades: `_cents` sao centimos inteiros, `_bp` sao pontos base
    | (500 = 5%), `_micros` sao milionesimos de euro. Os micros aparecem onde o
    | centimo nao chega: 0,1420 EUR/kWh guardado em centimos era 14, e o custo
    | de energia de uma peca saia 4% ao lado. Ver App\Support\Micros.
    |
    */

    /*
     * Custos de operacao
     */

    // O que a luz custa. E o unico numero da conta da energia que NAO e da
    // impressora: o contrato e da casa, a potencia e que e da maquina.
    'electricity_price_micros_per_kwh' => 142_000,

    // Quanto vale a hora de quem trabalha na peca. Nao se confunde com as
    // horas de maquina: a impressora a trabalhar sozinha nao e mao de obra, e
    // cobra-la como tal castigava exatamente as pecas pequenas que demoram
    // muito tempo — o oposto do que este negocio precisa de vender.
    'labor_rate_micros_per_hour' => 8_000_000,

    // Minutos de trabalho ATIVO por peca, quando a variante nao diz outra
    // coisa: preparar, trocar filamento, tirar da mesa, rebarbar, limpar,
    // montar, verificar, ensacar. So o tempo em que ha mesmo alguem a mexer.
    'active_labor_minutes' => 5,

    // Minutos que se gastam UMA vez por mesa, em modo lote: montar a mesa,
    // lancar o trabalho, tirar a placa no fim. Dilui-se por todas as pecas do
    // lote, e e por isso que imprimir doze de uma vez sai mais barato por peca
    // do que doze impressoes separadas.
    'setup_labor_minutes' => 5,

    /*
     * Risco
     */

    // Percentagem de impressoes que falham, em pontos base (500 = 5%).
    //
    // NAO se soma ao custo: o custo DIVIDE-SE por (1 - taxa). A diferenca nao
    // e cosmetica — somar 5% recupera menos do que se perdeu, porque a peca
    // falhada tambem gastou filamento, luz e horas de maquina. Com a divisao,
    // as pecas que saem boas pagam mesmo as que se deitaram fora.
    'failure_rate_bp' => 500,

    /*
     * Margens
     */

    // A margem que quero ter quando vendo a um revendedor. Margem sobre a
    // VENDA, nao markup sobre o custo: 40% aqui quer dizer que 40 centimos de
    // cada euro faturado sobram, e o preco sai de custo / (1 - 0,40).
    'target_wholesale_margin_bp' => 4_000,

    // A margem que quero DEIXAR a quem me compra para revender. O preco ao
    // cliente sai do preco de revenda, e nao do meu custo: e o que garante que
    // uma loja consegue mesmo viver do que compra aqui.
    'target_reseller_margin_bp' => 4_000,

    // Chao do preco de revenda, para objetos muito pequenos nao irem a zero.
    // E ELE que protege as pecas baratas — abaixo de ~0,90 EUR de custo real e
    // este numero, e nao a margem, que decide o preco.
    'minimum_wholesale_price_cents' => 150,

    /*
     * Custos de venda
     */

    // Comissoes do canal (marketplace, terminal, plataforma). A ZERO por
    // omissao, e a zero nem aparecem na calculadora.
    //
    // Ficam FORA do custo industrial de proposito: nao custam nada produzir, e
    // metidas no custo contaminavam o preco de revenda de pecas que se vendem
    // por outros canais. Entram so no lucro liquido, e por isso o preco nao se
    // mexe quando estes numeros mudam — o que se mexe e o que sobra.
    'sales_channel_fixed_fee_cents' => 0,
    'sales_channel_percentage_fee_bp' => 0,

    /*
     * Rede de seguranca: a maquina imaginaria
     *
     * Usados SO quando nao ha nenhuma impressora ativa. Nao sao definicoes e
     * nao aparecem em /admin/definicoes: quem manda no custo da maquina sao os
     * perfis (/admin/impressoras), e este caminho e um estado que a interface
     * ja sinaliza como partido ("cria uma impressora"). Promove-los a chaves
     * de settings era dar a entender que ha aqui uma escolha a fazer.
     *
     * Os valores sao os de uma Bambu Lab A1.
     */
    'fallback_printer_power_watts' => 145,
    'fallback_printer_purchase_price_cents' => 40_000,
    'fallback_printer_lifetime_hours' => 4_000,
    'fallback_printer_maintenance_micros_per_hour' => 40_000,

];
