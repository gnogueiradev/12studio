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
        Schema::create('checkouts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Snapshot congelado no "Finalizar compra": itens, precos, surcharges
            // e personalizacoes. E NISTO que o webhook confia — o carrinho (cookie)
            // pode mudar depois do redirect para o Stripe.
            $table->json('items_snapshot');
            $table->unsignedInteger('subtotal_cents');
            $table->string('stripe_session_id', 255)->nullable()->unique();
            $table->string('status', 20)->default('open');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('checkouts');
    }
};
