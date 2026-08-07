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
        // Auditoria completa: TODA a alteracao de stock escreve uma linha aqui
        // (venda, reposicao por cancelamento, ajuste manual, encomenda manual,
        // carga inicial). restrictOnDelete: variante com movimentos e historial
        // comercial — nunca pode ser apagada fisicamente.
        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('variant_id')->constrained()->restrictOnDelete();
            $table->integer('delta');
            $table->string('reason', 30);
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 300)->nullable();
            $table->timestamps();

            $table->index(['variant_id', 'created_at'], 'stock_movements_variant_created_idx');
        });

        // Reservas SO para pagamentos assincronos pendentes (Multibanco): o
        // voucher pode ser pago horas/dias depois ou nunca. Cartao pago decrementa
        // stock diretamente, sem reserva. O sweep do scheduler liberta reservas
        // com expires_at ultrapassado — rede de seguranca para eventos Stripe
        // atrasados ou perdidos.
        Schema::create('stock_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('qty');
            $table->timestamp('expires_at');
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->index(['expires_at', 'released_at'], 'stock_reservations_sweep_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_reservations');
        Schema::dropIfExists('stock_movements');
    }
};
