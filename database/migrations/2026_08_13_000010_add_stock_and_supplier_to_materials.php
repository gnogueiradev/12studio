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
        Schema::table('materials', function (Blueprint $table): void {
            // Familia do polimero (PLA, PETG...). O `name` e comercial e ja
            // distingue "PLA" de "PLA Silk"; a familia agrupa-os.
            $table->string('family', 20)->nullable()->after('name');
            // Quem vende o rolo. Entra na pesquisa da listagem — e a segunda
            // coisa que se procura, a seguir ao nome.
            $table->string('supplier', 60)->nullable()->after('family');
            $table->unsignedSmallInteger('spools_in_stock')->default(0)->after('price_per_kg_cents');
            // Zero significa "nao avisar", nao "avisar sempre": o default tem de
            // deixar os materiais que ja existem em paz depois desta migracao.
            $table->unsignedSmallInteger('min_spools')->default(0)->after('spools_in_stock');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('materials', function (Blueprint $table): void {
            $table->dropColumn(['family', 'supplier', 'spools_in_stock', 'min_spools']);
        });
    }
};
