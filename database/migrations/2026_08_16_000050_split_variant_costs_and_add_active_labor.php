<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A variante passa a distinguir o que embala do que se monta, e a poder dizer
 * quanto tempo humano leva.
 *
 * O `extra_cost_cents` era um saco unico ("imanes, feltro, caixa"). A formula
 * nova quer as duas parcelas separadas — sao decisoes de negocio diferentes:
 * a embalagem e mais ou menos igual para tudo o que sai da loja, os
 * componentes sao especificos da peca. Como nao ha maneira de saber que
 * fracao do que la esta era caixa, o valor que existe fica em COMPONENTES: e
 * a leitura conservadora (a embalagem entra a zero e o dono preenche-a quando
 * quiser), e nao inventa um numero que ninguem escreveu.
 *
 * O `active_labor_minutes` nasce nullable porque null quer dizer alguma coisa:
 * "usa a definicao global". Uma peca que leve o tempo normal nao tem de
 * repetir o numero, e mudar a omissao global apanha-a. Zero e diferente de
 * null — zero e "esta peca nao leva trabalho nenhum".
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // O rename sozinho na sua propria chamada, como na
        // 2026_08_13_000040: misturar rename e add no mesmo Blueprint deixa o
        // grammar do SQLite a reconstruir a tabela com o nome antigo.
        Schema::table('variants', function (Blueprint $table): void {
            $table->renameColumn('extra_cost_cents', 'components_cost_cents');
        });

        Schema::table('variants', function (Blueprint $table): void {
            $table->unsignedInteger('packaging_cost_cents')->nullable()->after('components_cost_cents');
            $table->unsignedSmallInteger('active_labor_minutes')->nullable()->after('printing_time_minutes');
        });
    }

    /**
     * Reverse the migrations.
     *
     * As colunas novas caem PRIMEIRO, e a ordem nao e estetica: o nome
     * `packaging_cost_cents` ja existiu nesta tabela (era o que a
     * 2026_08_13_000040 renomeou para `extra_cost_cents`). Renomear antes de
     * largar dava duas colunas com o mesmo nome.
     */
    public function down(): void
    {
        Schema::table('variants', function (Blueprint $table): void {
            $table->dropColumn(['packaging_cost_cents', 'active_labor_minutes']);
        });

        Schema::table('variants', function (Blueprint $table): void {
            $table->renameColumn('components_cost_cents', 'extra_cost_cents');
        });
    }
};
