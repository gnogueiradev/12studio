<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Aditiva: as encomendas que ja existem ficam sem codigo e com desconto 0,
     * e os totais e os estados nao mudam.
     *
     * Sem `constrained()` de proposito: numa tabela que ja existe, em SQLite, o
     * Laravel reconstruia a tabela `orders` inteira para acrescentar a FK.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('promo_code_id')->nullable()->index();
            $table->string('promo_code', 40)->nullable();
            $table->unsignedInteger('discount_cents')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex(['promo_code_id']);
            $table->dropColumn(['promo_code_id', 'promo_code', 'discount_cents']);
        });
    }
};
