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
        Schema::create('carts', function (Blueprint $table): void {
            $table->id();
            // O segredo que vai no cookie: quem o tem, tem o carrinho.
            $table->string('token', 64)->unique();
            $table->string('promo_code', 40)->nullable();
            $table->timestamps();
        });

        Schema::create('cart_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('qty');
            $table->json('personalization')->nullable();
            // md5 da personalizacao limpa: a mesma escolha cai sempre na mesma linha.
            $table->string('personalization_hash', 32);
            $table->timestamps();

            $table->unique(['cart_id', 'variant_id', 'personalization_hash']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
    }
};
