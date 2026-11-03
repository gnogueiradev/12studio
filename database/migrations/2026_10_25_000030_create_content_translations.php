<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * As traducoes do conteudo da montra (ES, EN, FR). O portugues continua nas
 * proprias tabelas — nao ha migracao de dados nem nada muda na Etsy.
 *
 * - product_translations / category_translations: uma linha por idioma. O
 *   `source_hash` e o hash do texto portugues no momento em que se traduziu:
 *   quando o portugues muda, a traducao aparece como desatualizada.
 * - colors / materials: so rotulos de filtro, por isso um JSON por idioma.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->string('seo_title')->nullable();
            $table->string('seo_description')->nullable();
            $table->text('search_text')->nullable();
            $table->string('source_hash', 64)->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'locale']);
            $table->index('locale');
        });

        Schema::create('category_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->string('source_hash', 64)->nullable();
            $table->timestamps();

            $table->unique(['category_id', 'locale']);
        });

        Schema::table('colors', function (Blueprint $table): void {
            $table->json('name_i18n')->nullable();
        });

        Schema::table('materials', function (Blueprint $table): void {
            $table->json('name_i18n')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('materials', fn (Blueprint $table) => $table->dropColumn('name_i18n'));
        Schema::table('colors', fn (Blueprint $table) => $table->dropColumn('name_i18n'));
        Schema::dropIfExists('category_translations');
        Schema::dropIfExists('product_translations');
    }
};
