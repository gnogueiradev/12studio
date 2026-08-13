<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Uma etiqueta deixa de pertencer ao produto.
 *
 * A tabela nasceu em 000020 como "segundo eixo de organizacao" do catalogo e so
 * do catalogo: um unico vocabulario, um unico pivot. Mas a mesma ideia serve o
 * cliente ("revendedor", "mau pagador") e a encomenda ("urgente", "oferta"), que
 * hoje vivem em texto livre no admin_note — onde nao se filtram nem se contam.
 *
 * O `scope` e o que torna isso possivel sem transformar as sugestoes em ruido:
 * "natal" nunca se oferece ao preencher uma ficha de cliente. A unicidade passa
 * de global a `(scope, slug)`, portanto "urgente" em encomendas e "urgente" em
 * produtos sao duas linhas — e sao mesmo duas coisas diferentes.
 *
 * O default 'product' faz o backfill sozinho e mantem a migracao
 * backwards-compatible: o codigo antigo continua a escrever tags de produto sem
 * saber que a coluna existe.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        /*
         * Chamadas separadas, pela razao de sempre no SQLite: largar um indice,
         * acrescentar uma coluna e criar outro indice reconstroem a tabela cada
         * um por si, e misturados no mesmo Blueprint deixam o grammar a procurar
         * o que ja largou. Mesmo padrao do decouple_colors_from_materials.
         *
         * IF EXISTS e nao dropUnique(): o down() nao repoe estes indices (ver la
         * o porque), portanto um migrate a seguir a um rollback passaria aqui
         * uma segunda vez.
         */
        DB::statement('DROP INDEX IF EXISTS tags_name_unique');
        DB::statement('DROP INDEX IF EXISTS tags_slug_unique');

        Schema::table('tags', function (Blueprint $table): void {
            $table->string('scope', 10)->default('product')->after('id');
        });

        /*
         * Binario chega, ao contrario do colors_name_unique: o slug ja sai
         * minusculo e sem acentos do Str::slug, portanto "Natal" e "natal"
         * colapsam antes de chegar ao indice. O nome fica sem unique — quem
         * manda na identidade de uma etiqueta e o slug.
         *
         * Sem indice proprio em `scope`: este unique comeca por ele, e as
         * consultas por ambito ("todas as etiquetas de cliente") usam o mesmo
         * prefixo.
         */
        Schema::table('tags', function (Blueprint $table): void {
            $table->unique(['scope', 'slug'], 'tags_scope_slug_unique');
        });
    }

    /**
     * Reverse the migrations.
     *
     * Os uniques de `name` e `slug` NAO se repoem. Depois desta migracao podem
     * existir "urgente" em produtos e "urgente" em encomendas, duas linhas
     * legitimas com o mesmo slug; repor um unique global rebentaria a meio do
     * rollback, e a alternativa — apagar uma delas — seria a migracao a decidir
     * sozinha que dados deitar fora.
     *
     * Na pratica isto custa pouco: o rollback do Jenkins repoe a imagem anterior
     * sem reverter migracoes (docs/plano.md), portanto este down() so corre em
     * desenvolvimento.
     */
    public function down(): void
    {
        Schema::table('tags', function (Blueprint $table): void {
            $table->dropUnique('tags_scope_slug_unique');
        });

        Schema::table('tags', function (Blueprint $table): void {
            $table->dropColumn('scope');
        });
    }
};
