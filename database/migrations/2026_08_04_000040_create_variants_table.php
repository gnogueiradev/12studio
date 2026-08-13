<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('sku', 60)->unique();
            // restrictOnDelete: uma cor com variantes nao pode ser apagada —
            // regra global de eliminacao logica (arquivar, nunca apagar historial).
            $table->foreignId('color_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('size_label', 60)->nullable();

            // Precos em centimos inteiros, IVA INCLUIDO (montra PT).
            $table->unsignedInteger('price_cents');
            $table->unsignedInteger('compare_at_cents')->nullable();

            // stock nunca fica negativo: todas as escritas sao updates condicionais
            // `WHERE stock - reserved_stock >= qty` (StockService, Fase 3).
            // reserved_stock cobre a janela Multibanco (voucher gerado, nao pago).
            // available = stock - reserved_stock e SEMPRE calculado, nunca guardado.
            $table->unsignedInteger('stock')->default(0);
            $table->unsignedInteger('reserved_stock')->default(0);
            $table->unsignedSmallInteger('low_stock_threshold')->default(3);
            $table->boolean('is_default')->default(false);
            $table->boolean('active')->default(true);

            // Custos de producao — alimentam a calculadora de precos.
            // Ver a migracao 2026_08_13_000040_rework_variant_cost_columns: as
            // quatro colunas abaixo do tempo saem quando a formula muda, e o
            // printer_profile_id entra. Ficam aqui como estavam no dia em que
            // esta migracao correu — reescrever historia era mentir sobre ela.
            $table->unsignedInteger('filament_weight_grams')->nullable();
            $table->unsignedInteger('printing_time_minutes')->nullable();
            $table->unsignedInteger('labor_minutes')->nullable();
            $table->unsignedInteger('packaging_cost_cents')->nullable();
            // Se preenchido, ignora o calculo automatico (tempo x wattagem x kWh).
            $table->unsignedInteger('energy_cost_cents')->nullable();
            $table->unsignedTinyInteger('failure_rate_percent')->nullable();

            // Peso/dimensoes de envio (o filamento nao e o peso do pacote!).
            // V1 apenas armazena e mostra; portes por peso ficam para depois.
            $table->unsignedInteger('product_weight_grams')->nullable();
            $table->unsignedInteger('package_weight_grams')->nullable();
            $table->unsignedSmallInteger('length_mm')->nullable();
            $table->unsignedSmallInteger('width_mm')->nullable();
            $table->unsignedSmallInteger('height_mm')->nullable();

            $table->timestamps();

            $table->index(['product_id', 'active'], 'variants_product_active_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('variants');
    }
};
