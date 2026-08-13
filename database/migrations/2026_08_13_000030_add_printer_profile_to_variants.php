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
            // Nullable: a variante sem impressora escolhida usa a predefinida.
            // Prende-la a uma impressora obrigava a escolher uma em todas as
            // variantes que ja existem, e o custo/hora quase nunca difere.
            //
            // restrictOnDelete: uma impressora com variantes nao se apaga —
            // regra global de eliminacao logica (arquivar, nunca apagar
            // historial), a mesma do color_id ao lado.
            $table->foreignId('printer_profile_id')
                ->nullable()
                ->after('printing_time_minutes')
                ->constrained()
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Em duas chamadas: no SQLite cada uma reconstroi a tabela, e largar a
        // chave estrangeira e a coluna no mesmo Blueprint deixa o grammar a
        // procurar uma coluna que ja nao existe.
        Schema::table('variants', function (Blueprint $table): void {
            $table->dropForeign(['printer_profile_id']);
        });

        Schema::table('variants', function (Blueprint $table): void {
            $table->dropColumn('printer_profile_id');
        });
    }
};
