<?php

use App\Support\SearchText;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Duas colunas para a montra:
 *  - search_text: nome e descricao normalizados (SearchText), para a pesquisa
 *    do catalogo encontrar "peca" quando se escreve "peça";
 *  - sort_order: a ordem manual dentro dos destaques (menor primeiro).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->text('search_text')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
        });

        DB::table('products')->orderBy('id')->chunkById(200, function ($products): void {
            foreach ($products as $product) {
                DB::table('products')->where('id', $product->id)->update([
                    'search_text' => SearchText::normalize($product->name, $product->description),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['search_text', 'sort_order']);
        });
    }
};
