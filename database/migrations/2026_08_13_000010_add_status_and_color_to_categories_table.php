<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O booleano `active` so sabia dizer duas coisas, e o backoffice precisa de
     * tres: visivel no menu da loja, oculta (so por link directo) e arquivada
     * (retirada). Oculta e arquivada nao sao a mesma intencao — uma e uma
     * categoria viva que ainda nao se anuncia, a outra e uma campanha que
     * acabou — e um booleano so conseguia guardar uma delas.
     */
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->string('status', 10)->default('visible')->after('slug')->index();
            // Hex com # de uma paleta fixa (App\Support\CategoryColors).
            $table->string('color', 7)->nullable()->after('description');
        });

        // `active = false` vira ARQUIVADA e nao oculta: e o que o booleano
        // significava mesmo ate aqui — o destroy chamava archive() e a listagem
        // rotulava a linha de "Arquivada". Nenhuma categoria existente esteve
        // alguma vez "oculta", porque esse estado nao cabia na coluna.
        //
        // Via DB::table e nao pelo modelo: o Category ja nao conhece `active`
        // depois deste commit, e uma migracao que dependa da forma actual do
        // modelo deixa de correr limpa numa base vazia daqui a uns meses.
        DB::table('categories')->where('active', false)->update(['status' => 'archived']);

        Schema::table('categories', function (Blueprint $table): void {
            $table->dropColumn('active');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->boolean('active')->default(true)->after('slug');
        });

        // A viagem de volta perde informacao: oculta e arquivada colapsam as
        // duas em `false`, porque e so isso que o booleano sabe guardar.
        DB::table('categories')->where('status', '!=', 'visible')->update(['active' => false]);

        Schema::table('categories', function (Blueprint $table): void {
            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'color']);
        });
    }
};
