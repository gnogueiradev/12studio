<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alinha as colunas de custo das variantes com a nova formula de precos.
 *
 * A formula antiga (documentada em docs/plano.md, nunca implementada) somava
 * filamento + energia + trabalho + embalagem + falhas, cada parcela com a sua
 * coluna. A nova soma material + tempo de maquina + manuseamento + risco +
 * extras, e tres dessas colunas deixaram de ter dono:
 *
 *   labor_minutes         -> o tempo de maquina ja paga a atencao humana, e o
 *                            trabalho avulso e o manuseamento (tabela por peso)
 *   energy_cost_cents     -> a energia esta dentro do custo/hora da impressora
 *   failure_rate_percent  -> a reserva de falha passou a ser uma definicao
 *                            global, nao um numero por variante
 *
 * A quarta muda de nome: packaging_cost_cents ficou pequeno para o que o campo
 * passou a ser — caixa, ima, feltro, argola, autocolante, componente
 * eletronico. Qualquer coisa que se soma ao custo desta peca.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // O rename sozinho na sua propria chamada: o SQLite tem ALTER TABLE
        // RENAME COLUMN nativo, mas misturar rename e drop no mesmo Blueprint
        // deixa o grammar a reconstruir a tabela com o nome antigo.
        Schema::table('variants', function (Blueprint $table): void {
            $table->renameColumn('packaging_cost_cents', 'extra_cost_cents');
        });

        Schema::table('variants', function (Blueprint $table): void {
            $table->dropColumn(['labor_minutes', 'energy_cost_cents', 'failure_rate_percent']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * Volta atras sem perder nada NA PRATICA: as tres colunas nunca tiveram
     * regra de validacao, campo de formulario nem leitura, e por isso estao
     * todas a null. Em principio a perda existe — se alguem lhes escrever por
     * fora antes do rollback, esses valores nao voltam.
     */
    public function down(): void
    {
        Schema::table('variants', function (Blueprint $table): void {
            $table->unsignedInteger('labor_minutes')->nullable()->after('printing_time_minutes');
            $table->unsignedInteger('energy_cost_cents')->nullable()->after('labor_minutes');
            $table->unsignedTinyInteger('failure_rate_percent')->nullable()->after('energy_cost_cents');
        });

        Schema::table('variants', function (Blueprint $table): void {
            $table->renameColumn('extra_cost_cents', 'packaging_cost_cents');
        });
    }
};
