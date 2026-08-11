<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Segundo eixo de organizacao, ao lado das categorias: um produto tem
        // UMA categoria ("Vasos") mas varias tags ("natal", "presente",
        // "minimalista"). Alimenta os filtros da montra.
        Schema::create('tags', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 60)->unique();
            $table->string('slug', 80)->unique();
            $table->timestamps();
        });

        // Excepcao consciente a regra de eliminacao logica: uma tag sem
        // produtos e ruido, nao historial — nenhuma encomenda a referencia,
        // por isso apaga-se mesmo e o pivot cai com ela.
        Schema::create('product_tag', function (Blueprint $table): void {
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();

            $table->primary(['product_id', 'tag_id']);
            $table->index('tag_id', 'product_tag_tag_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_tag');
        Schema::dropIfExists('tags');
    }
};
