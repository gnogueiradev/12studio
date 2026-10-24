<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A biblioteca de imagens dos blocos da montra (hero, galerias, banners).
 * As fotografias de produto continuam em product_images; estas sao as que nao
 * pertencem a produto nenhum. Mesmas versoes reduzidas (GenerateImageVariants).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table): void {
            $table->id();
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('alt')->nullable();
            $table->string('mime')->nullable();
            $table->unsignedInteger('size')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->json('variants')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
