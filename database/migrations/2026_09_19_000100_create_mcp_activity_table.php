<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rasto de tudo o que o Claude faz pelo MCP. Tabela nova — nao toca em
     * nada que ja exista.
     *
     * Sem FKs de proposito: o rasto tem de sobreviver ao token revogado e
     * purgado (o passport:purge apaga linhas de oauth_access_tokens), e uma
     * linha de auditoria nunca desaparece em cascata.
     */
    public function up(): void
    {
        Schema::create('mcp_activity', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            // Id do token do Passport (string de 80 chars), nao inteiro.
            $table->string('token_id', 100)->nullable()->index();
            $table->string('client', 150)->nullable();
            $table->string('tool', 100);
            // ok | validation_error | error | denied
            $table->string('result', 20);
            $table->json('arguments')->nullable();
            $table->json('changes')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_activity');
    }
};
