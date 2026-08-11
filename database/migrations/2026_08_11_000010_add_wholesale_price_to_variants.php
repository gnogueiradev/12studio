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
        Schema::table('variants', function (Blueprint $table): void {
            // Preco praticado quando a peca sai para revenda (Vinted, lojas,
            // mercados). NAO aparece na montra — serve para o admin aplicar
            // com um clique ao registar uma encomenda manual.
            $table->unsignedInteger('wholesale_price_cents')->nullable()->after('compare_at_cents');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('variants', function (Blueprint $table): void {
            $table->dropColumn('wholesale_price_cents');
        });
    }
};
