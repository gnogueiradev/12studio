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
    |   2. os arredondamentos: a regra da margem continua a usar o degrau de
    |      0,50 EUR (revenda) e as faixas 0,50 / 1 / 5 EUR (cliente); os tres
    |      precos sugeridos terminam sempre em ,49 ou ,99, para cima. Sao
    |      regras comerciais fixas e vivem no PricingCalculator.
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

    // O IVA que vem DENTRO dos custos que o admin escreve — o EUR/kg do
    // filamento, o preco das impressoras, a manutencao, a embalagem, os
    // componentes, a tarifa da luz — em pontos base (2300 = 23%).
    //
    // A loja cobra IVA nas vendas e deduz o das compras, portanto o imposto
    // que se pagou ao fornecedor nao e custo: a calculadora tira-o de cada
    // parcela antes de fazer a conta. A mao de obra fica de fora — o trabalho
    // de quem faz a peca nao traz IVA nenhum para tirar.
    //
    // A ZERO quer dizer "ja escrevo os custos sem IVA", e nada e tirado. O IVA
    // da VENDA nao vive aqui: e a `vat_rate` de cada produto.
    'cost_vat_rate_bp' => 2_300,

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
     * Orcamentos (calculadora livre e pricing_calculate)
     *
     * So contam quando a peca e um ORCAMENTO, e nunca no custo de uma variante
     * do catalogo: e esse custo que a Etsy e o travao de precos do MCP leem, e
     * nao pode mudar por uma regra que so existe para trabalhos por medida.
     */

    // O minimo de trabalho de um orcamento, por impressao: falar com o
    // cliente, preparar o ficheiro, lancar. Cinco minutos de acabamento nao
    // pagam isso. Substitui o trabalho ativo quando este e menor.
    'quote_min_labor_minutes' => 20,

    // Cada placa a mais e outra vez tirar, limpar e relancar.
    'labor_minutes_per_extra_plate' => 5,

    // Cada troca manual de filamento e ficar a espera da pausa e trocar a bobine.
    'labor_minutes_per_color_change' => 5,

    // O valor da hora de DESIGN, a parte da mao de obra: modelar nao e
    // rebarbar. Cobra-se uma vez por trabalho e so no preco Premium.
    'design_rate_micros_per_hour' => 15_000_000,

    /*
     * Os tres precos sugeridos (Minimo, Recomendado, Premium)
     *
     * Cada nivel e o MAIOR de tres precos sem IVA: a regra da margem de
     * sempre, custo + horas de impressao x lucro por hora, e (so em
     * orcamentos) custo + lucro minimo do trabalho a dividir pelas pecas. E a
     * segunda que paga as pecas leves e demoradas, que a margem sobre o custo
     * deixava a 0,85 EUR por hora de impressora.
     */
    'tier_minimum_profit_micros_per_hour' => 1_400_000,
    'tier_recommended_profit_micros_per_hour' => 1_900_000,
    'tier_premium_profit_micros_per_hour' => 2_300_000,

    'tier_minimum_job_profit_cents' => 500,
    'tier_recommended_job_profit_cents' => 800,
    'tier_premium_job_profit_cents' => 1_000,

    // A revenda sugerida, em fracao do Recomendado (5000 = 50%)...
    'wholesale_share_of_recommended_bp' => 5_000,

    // ...mas nunca abaixo de custo x (1 + IVA) x isto (13000 = 1,3x).
    'wholesale_floor_markup_bp' => 13_000,

    // Avisa quando o custo do filamento calculado e o do slicer diferem mais
    // do que isto (1500 = 15%).
    'filament_reference_tolerance_bp' => 1_500,

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
