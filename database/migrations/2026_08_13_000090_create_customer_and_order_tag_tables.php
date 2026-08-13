<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Os outros dois pivots das etiquetas, gemeos do product_tag de 000020.
 *
 * Dois pivots explicitos e nao uma tabela polimorfica (`taggables` com
 * taggable_id + taggable_type): o taggable_id nao aceita FK, e este projeto liga
 * foreign_key_constraints no SQLite de proposito (config/database.php). Com
 * pivots proprios, apagar um cliente leva as suas etiquetas atras sem ninguem
 * escrever codigo para isso.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        /*
         * `tag_user` e nao `customer_tag`: cliente aqui e um User com
         * is_admin = false, e o nome segue a convencao do Eloquent (as duas
         * tabelas por ordem alfabetica), o que dispensa declarar a tabela na
         * relacao. Nao se le como "etiquetas de administrador" — um admin nao
         * tem etiquetas, so o cliente e que tem.
         */
        Schema::create('tag_user', function (Blueprint $table): void {
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->primary(['tag_id', 'user_id']);
            $table->index('user_id', 'tag_user_user_idx');
        });

        // Uma encomenda nunca se apaga, mas a etiqueta sim: o cascade que
        // interessa aqui e o do tag_id.
        Schema::create('order_tag', function (Blueprint $table): void {
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();

            $table->primary(['order_id', 'tag_id']);
            $table->index('tag_id', 'order_tag_tag_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_tag');
        Schema::dropIfExists('tag_user');
    }
};
