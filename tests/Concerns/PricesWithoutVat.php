<?php

namespace Tests\Concerns;

use App\Services\PricingSettings;
use App\Services\SettingService;

/**
 * Desliga o IVA da calculadora: custos ja liquidos e venda a 0%.
 *
 * Os testes que fixam a formula ao micro — o caso de referencia calculado a
 * mao, o lote, os arredondamentos — foram escritos antes de a calculadora
 * saber o que e IVA, e continuam a descrever a conta que ela faz por baixo.
 * Com as duas taxas a zero a formula nova tem de dar EXATAMENTE os numeros da
 * antiga; e essa a ancora de regressao, e e por isso que estes testes desligam
 * o IVA em vez de verem os seus numeros reescritos.
 *
 * O que o IVA faz esta no PricingVatTest e no PricingVatPageTest.
 *
 * Um produto criado pela factory continua a nascer com 23%: quem calcula a
 * partir de um produto tem de o criar com `vat_rate => 0`.
 */
trait PricesWithoutVat
{
    protected function pricesWithoutVat(): void
    {
        config(['shop.default_vat_rate' => 0]);

        app(SettingService::class)->set(PricingSettings::KEY_COST_VAT_RATE_BP, 0);
    }
}
