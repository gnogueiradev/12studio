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
        // Morada guardada do cliente (1 por cliente no V1) — usada a partir da
        // Fase 5 para prefill do checkout. As encomendas guardam SEMPRE um
        // snapshot json proprio; nunca FK para esta tabela mutavel.
        Schema::create('addresses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('line1', 190);
            $table->string('line2', 190)->nullable();
            // Formato PT: NNNN-NNN.
            $table->string('postal_code', 8);
            $table->string('city', 80);
            $table->string('country', 2)->default('PT');
            $table->string('phone', 30)->nullable();
            $table->string('nif', 20)->nullable();
            $table->boolean('is_default')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('addresses');
    }
};
