<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Apagar" um produto, que ate aqui so se arquivava.
 *
 * Um arquivado continua na listagem, com a sua chip — e o que se quer de um
 * produto que saiu de linha. O que faltava era a reciclagem: o produto de
 * teste, o duplicado, o engano. Esses saem da vista, mas a linha fica, porque
 * as encomendas e os movimentos de stock apontam para as variantes dela.
 *
 * Aditiva: um `ALTER TABLE ADD COLUMN` simples, sem indice nem chave
 * estrangeira, portanto o SQLite nao reconstroi a tabela. Os produtos que ja
 * existem ficam com NULL — "nao apagado" — e nenhum dado gravado muda. Sem
 * indice de proposito: sao dezenas de produtos, e criar um aqui era a unica
 * coisa que tornava esta migracao menos trivial.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });
    }
};
