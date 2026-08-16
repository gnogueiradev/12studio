<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quem foi que escondeu esta variante.
 *
 * Desmarcar o Silk na cor rosa esconde as variantes "Rosa Silk" — nunca as
 * apaga, que as encomendas antigas ainda lhes apontam. Quando o rosa silk
 * voltar ao stock e o material for remarcado, essas variantes tem de voltar.
 *
 * Sem esta coluna, "voltar" so podia significar reactivar TODAS as variantes
 * rosa/silk inactivas — incluindo as que o dono escondeu a mao por nao as
 * querer vender. `active` sozinho diz que a variante esta escondida; nao diz
 * porque, e e o porque que decide se ela pode ser ressuscitada.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('variants', function (Blueprint $table): void {
            // Default false: tudo o que ja la esta foi decisao de alguem, nao
            // do catalogo. A reconciliacao nao lhes toca.
            $table->boolean('hidden_by_palette')->default(false)->after('active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('variants', function (Blueprint $table): void {
            $table->dropColumn('hidden_by_palette');
        });
    }
};
