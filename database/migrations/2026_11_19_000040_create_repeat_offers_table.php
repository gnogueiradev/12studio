<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A oferta para voltar a comprar: uma por encomenda (a unica garante que
     * ninguem a recebe duas vezes), com o codigo que foi dado.
     */
    public function up(): void
    {
        Schema::create('repeat_offers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('promo_code_id')->constrained()->cascadeOnDelete();
            $table->string('email', 255)->index();
            $table->timestamp('sent_at');
            $table->timestamp('reminded_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repeat_offers');
    }
};
