<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quando o cliente deixou de querer ofertas. Nulo = nunca pediu. Coluna
     * simples e nula, para a tabela que ja existe nao ser reconstruida.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('marketing_opt_out_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('marketing_opt_out_at');
        });
    }
};
