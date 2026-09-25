<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Memoria dos alertas do Discord: o que ja foi avisado e quando (para o
     * verificador nao repetir o mesmo aviso de hora a hora) e marcas d'agua
     * (ate onde o resumo de leituras do MCP ja contou). Tabela nova — nao
     * toca em nada que ja exista.
     */
    public function up(): void
    {
        Schema::create('alert_states', function (Blueprint $table): void {
            $table->string('key', 150)->primary();
            $table->string('value')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_states');
    }
};
