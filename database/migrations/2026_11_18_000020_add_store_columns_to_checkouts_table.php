<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O retrato de uma compra da loja: quem compra, para onde vai, quanto
     * custa e de que carrinho veio. Tudo sem chaves estrangeiras — em SQLite
     * uma FK numa tabela que ja existe reconstroi-a — e tudo com valor por
     * omissao ou nulo, para a coluna se juntar sem mexer no que la esta.
     */
    public function up(): void
    {
        Schema::table('checkouts', function (Blueprint $table): void {
            // O segredo da pagina de regresso (/compra/{token}).
            $table->string('token', 64)->nullable()->unique();
            $table->unsignedBigInteger('cart_id')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->json('customer')->nullable();
            $table->json('shipping_address')->nullable();
            $table->unsignedInteger('shipping_cents')->default(0);
            $table->unsignedInteger('discount_cents')->default(0);
            $table->unsignedBigInteger('promo_code_id')->nullable();
            $table->string('promo_code', 40)->nullable();
            $table->unsignedInteger('total_cents')->default(0);
            $table->string('locale', 5)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('checkouts', function (Blueprint $table): void {
            $table->dropUnique(['token']);
            $table->dropColumn([
                'token', 'cart_id', 'order_id', 'customer', 'shipping_address', 'shipping_cents',
                'discount_cents', 'promo_code_id', 'promo_code', 'total_cents', 'locale',
            ]);
        });
    }
};
