<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('product_images', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            // Imagem pode pertencer a uma variante/cor especifica — a galeria
            // muda quando o cliente escolhe outra cor.
            $table->foreignId('variant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('color_id')->nullable()->constrained()->nullOnDelete();
            $table->string('path', 255);
            $table->string('alt', 160)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            // Imagem principal explicita (listagens, carrinho, OG, emails) —
            // nunca depender do sort_order para isto.
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });

        // Indice unico PARCIAL: no maximo UMA imagem principal por produto.
        // O Blueprint nao expressa indices parciais; o SQLite suporta-os
        // nativamente (mesmo padrao usado nas migracoes do projeto qrcode).
        // A escrita passa sempre por ImageService::setPrimary() transacional.
        DB::statement(
            'CREATE UNIQUE INDEX product_images_one_primary_per_product '
            .'ON product_images (product_id) WHERE is_primary = 1'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_images');
    }
};
