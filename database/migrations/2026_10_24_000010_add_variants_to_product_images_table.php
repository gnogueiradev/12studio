<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * As medidas do original e as versoes reduzidas (WebP por largura e o JPEG do
 * Open Graph) de cada fotografia. Nulas ate o GenerateImageVariants correr:
 * a montra serve o original enquanto isso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_images', function (Blueprint $table): void {
            $table->unsignedInteger('width')->nullable()->after('path');
            $table->unsignedInteger('height')->nullable()->after('width');
            $table->json('variants')->nullable()->after('height');
        });
    }

    public function down(): void
    {
        Schema::table('product_images', function (Blueprint $table): void {
            $table->dropColumn(['width', 'height', 'variants']);
        });
    }
};
