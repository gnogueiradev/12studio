<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A escolha feita no "Pagar". Nulo quer dizer um checkout de antes desta
     * funcionalidade, em que ninguem foi perguntado.
     */
    public function up(): void
    {
        Schema::table('checkouts', function (Blueprint $table): void {
            $table->boolean('marketing_opt_out')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('checkouts', function (Blueprint $table): void {
            $table->dropColumn('marketing_opt_out');
        });
    }
};
