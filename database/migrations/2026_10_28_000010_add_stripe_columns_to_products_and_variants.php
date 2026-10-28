<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O espelho do catalogo no Stripe: um Product por produto e um Price por
     * variante. Aditiva — tres colunas que nascem vazias e se enchem com o
     * `stripe:sync-products`.
     *
     * O valor do Price fica guardado ao lado do id porque no Stripe um Price
     * nao se edita: e comparando-o com o price_cents que se sabe quando e
     * preciso criar outro.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('stripe_product_id', 60)->nullable()->unique();
        });

        Schema::table('variants', function (Blueprint $table): void {
            $table->string('stripe_price_id', 60)->nullable()->unique();
            $table->unsignedInteger('stripe_price_cents')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('variants', function (Blueprint $table): void {
            $table->dropUnique(['stripe_price_id']);
            $table->dropColumn(['stripe_price_id', 'stripe_price_cents']);
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropUnique(['stripe_product_id']);
            $table->dropColumn('stripe_product_id');
        });
    }
};
