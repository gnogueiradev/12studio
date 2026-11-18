<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O SEO do rascunho, ao lado do publicado (seo), como os blocos: o que se
 * escreve no editor so vai para o ar ao publicar.
 *
 * So acrescenta uma coluna, sem copiar nada: null quer dizer "o rascunho do
 * SEO e igual ao publicado", e as paginas que ja existem ficam como estavam.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table): void {
            $table->json('draft_seo')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table): void {
            $table->dropColumn('draft_seo');
        });
    }
};
