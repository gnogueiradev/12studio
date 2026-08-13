<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Uma cor deixa de pertencer a um material.
 *
 * A migracao original justificava a FK assim: "uma cor pertence a UM material,
 * o que torna impossivel vender combinacoes inexistentes". A intencao era boa e
 * o efeito nao: obrigava a uma linha por par cor x material, a um servico
 * inteiro a escrever em leque e a uma camada de agrupamento por string no
 * backoffice, tudo para simular uma cor unica por cima de N linhas.
 *
 * A partir daqui uma cor e um nome e um tom. Quem tem preco e stock e a bobine
 * — o material —, e e ele que a variante aponta desde 000050.
 *
 * O `price_per_kg_cents` sai com a FK: era um override "sobre o material", e
 * sem material nao ha sobre o que fazer override.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        /*
         * Chamadas separadas, e nao uma. O SQLite recusa largar nativamente uma
         * coluna que esteja num indice, num UNIQUE ou numa FK, e o dropForeign
         * reconstroi a tabela — misturar tudo no mesmo Blueprint deixa o
         * grammar a procurar o que ja largou. Mesmo padrao do
         * rework_variant_cost_columns e do down() do
         * add_printer_profile_to_variants.
         *
         * IF EXISTS e nao dropUnique(): o down() desta migracao repoe a coluna
         * `material_id` mas nao o indice — repor um unique "por material" sobre
         * uma coluna toda a null era inventar uma garantia. Sem o IF EXISTS,
         * um migrate a seguir a um rollback rebentava aqui.
         */
        DB::statement('DROP INDEX IF EXISTS colors_material_name_unique');

        Schema::table('colors', function (Blueprint $table): void {
            $table->dropForeign(['material_id']);
        });

        Schema::table('colors', function (Blueprint $table): void {
            $table->dropColumn(['material_id', 'price_per_kg_cents']);
        });

        /*
         * COLLATE NOCASE, e por isso a mao: o unique('name') do Blueprint e
         * binario e deixava "preto" entrar ao lado de "Preto". Indice escrito
         * em SQL cru segue o precedente do indice parcial em product_images.
         *
         * Limite assumido: o NOCASE do SQLite so dobra ASCII A-Z, portanto
         * "Ambar" e "ambar" com acento continuam a caber os dois. A validacao
         * usa mb_strtolower e e mais apertada que o indice — que e a direcao
         * certa: recusa no formulario, nunca um erro de base de dados.
         */
        DB::statement('CREATE UNIQUE INDEX colors_name_unique ON colors (name COLLATE NOCASE)');
    }

    /**
     * Reverse the migrations.
     *
     * As colunas voltam VAZIAS. A ligacao cor -> material e os overrides de
     * preco nao voltam: foram descartados na fusao (000060), que tambem nao tem
     * volta. Por isso `material_id` regressa sem `constrained()` — uma FK sobre
     * uma coluna toda a null e uma promessa que ninguem cumpre — e o
     * colors_material_name_unique nao se repoe.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS colors_name_unique');

        Schema::table('colors', function (Blueprint $table): void {
            $table->unsignedBigInteger('material_id')->nullable()->after('id');
            $table->unsignedInteger('price_per_kg_cents')->nullable()->after('image');
        });
    }
};
