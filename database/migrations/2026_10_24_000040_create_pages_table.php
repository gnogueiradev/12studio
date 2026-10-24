<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * As paginas da montra montadas por blocos.
 *
 * - system: as que o site sempre tem (inicio, sobre, contacto, legais...),
 *   identificadas pela `key`. Nascem sozinhas com o conteudo de origem.
 * - landing: as que o dono cria no construtor, com URL proprio (`slug`) e,
 *   opcionalmente, datas de inicio e fim.
 *
 * O rascunho (draft_blocks) e o publicado (published_blocks) vivem lado a
 * lado: o site so muda quando se publica.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table): void {
            $table->id();
            $table->string('type');
            $table->string('key')->nullable()->unique();
            $table->string('slug')->nullable()->unique();
            $table->string('name');
            $table->string('status')->default('draft');
            $table->json('seo')->nullable();
            $table->json('draft_blocks');
            $table->json('published_blocks')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('page_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('page_id')->constrained()->cascadeOnDelete();
            $table->json('blocks');
            $table->json('seo')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_revisions');
        Schema::dropIfExists('pages');
    }
};
