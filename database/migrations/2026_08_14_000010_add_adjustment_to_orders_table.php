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
        Schema::table('orders', function (Blueprint $table): void {
            // A UNICA coluna monetaria com sinal da tabela — as outras
            // (subtotal, portes, total) sao unsigned de proposito. A
            // assinatura do tipo e, ela propria, a documentacao de que so
            // esta pode ser negativa: um desconto acordado depois de a
            // encomenda estar registada.
            $table->integer('adjustment_cents')->default(0)->after('shipping_cents');
            // Obrigatorio sempre que o ajuste nao e zero (validado no
            // UpdateOrderAdjustmentRequest). O paralelo ao nivel da linha e
            // o `price_override_reason` do OrderItem: mexer no preco e
            // permitido, mexer sem justificacao nao.
            $table->string('adjustment_reason', 200)->nullable()->after('adjustment_cents');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['adjustment_cents', 'adjustment_reason']);
        });
    }
};
