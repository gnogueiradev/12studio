<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A impressora deixa de ter um custo/hora escrito a mao e passa a ter os
 * numeros de que esse custo se faz.
 *
 * O `hourly_rate_cents` era um so numero a cobrir energia, desgaste,
 * manutencao e depreciacao. Servia para calcular, mas nao para decidir: nao
 * dizia quanto e que cada coisa pesava, e mudar de tarifa eletrica ou amortizar
 * a maquina mais depressa obrigava a reinterpretar o agregado em vez de mexer
 * no numero certo. A partir daqui o custo/hora e DERIVADO destas quatro
 * colunas mais a tarifa (que e global, e por isso vive nas definicoes).
 *
 * As quatro colunas nascem com valores por omissao CONSTANTES, e isso e
 * deliberado por duas razoes:
 *
 *   1. sao o backfill. 0,20 EUR/h nao se decompoe em potencia, preco e vida
 *      util — nao ha inversa. Uma Bambu Lab A1 a 400 EUR, 4000 h de vida, 145 W
 *      e 0,04 EUR/h de manutencao da ~0,16 EUR/h derivado, que e a ordem de
 *      grandeza certa para as linhas que ja existem;
 *   2. um default constante deixa o SQLite usar ALTER TABLE ADD COLUMN nativo.
 *      Sem ele o grammar RECONSTROI a tabela, e a reconstrucao levava com ela o
 *      indice unico parcial `printer_profiles_one_default` — criado por DDL cru
 *      na 2026_08_13_000020 e invisivel ao Blueprint. Duas impressoras
 *      predefinidas ao mesmo tempo e um bug que nao da erro nenhum.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('printer_profiles', function (Blueprint $table): void {
            // Potencia media durante uma impressao, em watts. Wh/h e W sao a
            // mesma unidade, e por isso nao ha aqui decimal nenhum para o
            // admin escrever: 0,145 kWh/h le-se 145 W.
            $table->unsignedSmallInteger('average_power_watts')->default(145)->after('name');

            // Quanto custou a maquina e quantas horas se espera que dure. E a
            // divisao destes dois que amortiza a impressora nas pecas.
            $table->unsignedInteger('purchase_price_cents')->default(40_000)->after('average_power_watts');
            $table->unsignedInteger('lifetime_hours')->default(4_000)->after('purchase_price_cents');

            // Reserva por hora para nozzle, hotend, correias, lubrificacao e
            // pecas de desgaste. Em MICROS e nao em centimos: 0,04 EUR/h
            // arredondado ao centimo era 4, e 0,035 EUR/h era 4 tambem.
            $table->unsignedInteger('maintenance_micros_per_hour')->default(40_000)->after('lifetime_hours');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('printer_profiles', function (Blueprint $table): void {
            $table->dropColumn([
                'average_power_watts',
                'purchase_price_cents',
                'lifetime_hours',
                'maintenance_micros_per_hour',
            ]);
        });
    }
};
