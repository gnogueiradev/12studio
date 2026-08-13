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
        Schema::create('materials', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 60)->unique();
            // Preco por kg do filamento — alimenta o calculo automatico de custo
            // (PricingCalculator). Cor pode ter override proprio.
            $table->unsignedInteger('price_per_kg_cents')->default(0);
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Uma cor pertence a UM material (PLA Preto e PETG Preto sao linhas
        // distintas). Isto torna impossivel vender combinacoes inexistentes e
        // permite swatches reais agrupados por material na montra.
        Schema::create('colors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('material_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('hex_color', 9);
            $table->string('image', 255)->nullable();
            $table->unsignedInteger('price_per_kg_cents')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['material_id', 'name'], 'colors_material_name_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('colors');
        Schema::dropIfExists('materials');
    }
};
