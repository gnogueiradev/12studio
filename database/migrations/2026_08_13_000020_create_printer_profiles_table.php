<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('printer_profiles', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 60)->unique();

            // Custo/hora da MAQUINA. Nao e so eletricidade: cobre desgaste,
            // correias, rolamentos, hotend, nozzle, mesa, lubrificacao,
            // manutencao, consumiveis, depreciacao e o simples facto de a
            // impressora estar ocupada. Um numero so, de proposito — assim
            // energia e desgaste nao se somam outra vez noutra parcela do
            // PricingCalculator.
            $table->unsignedInteger('hourly_rate_cents');

            $table->string('notes', 255)->nullable();

            // A impressora que a calculadora usa quando ninguem escolhe uma.
            $table->boolean('is_default')->default(false);
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['active', 'sort_order'], 'printer_profiles_active_idx');
        });

        // Indice unico PARCIAL: no maximo UMA impressora predefinida. Mesmo
        // padrao do product_images.is_primary — o Blueprint nao expressa
        // indices parciais e o SQLite suporta-os nativamente. A escrita passa
        // sempre pelo PrinterProfileService::setDefault(), transacional.
        DB::statement(
            'CREATE UNIQUE INDEX printer_profiles_one_default '
            .'ON printer_profiles (is_default) WHERE is_default = 1'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // O indice parcial morre com a tabela.
        Schema::dropIfExists('printer_profiles');
    }
};
