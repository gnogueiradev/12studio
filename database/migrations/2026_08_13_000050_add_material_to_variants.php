<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * O material passa a ser um eixo da variante, ao lado da cor e do tamanho.
 *
 * Ate aqui a variante so sabia o material ATRAVES da cor — `variants.color_id`
 * -> `colors.material_id` —, porque uma cor pertencia a um material. Nas
 * migracoes seguintes essa ligacao desaparece, e sem esta coluna a variante
 * ficava sem saber em que filamento e feita.
 *
 * TEM de correr ANTES da fusao de cores (000060): o preenchimento abaixo le o
 * material da linha EXATA que a variante aponta. Depois da fusao, a variante
 * que apontava para "PETG Preto" ja foi remapeada para a linha sobrevivente
 * (provavelmente a do PLA) e o material saia errado — sem erro nenhum.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('variants', function (Blueprint $table): void {
            // Nullable: ha variantes com `color_id` nulo, e portanto sem
            // material a herdar. Exigir material aqui obrigava a inventar um
            // para elas. Que a matriz de criacao exija cor E material e regra
            // do formulario, nao da tabela.
            //
            // restrictOnDelete: um material com variantes nao se apaga — regra
            // global de eliminacao logica, a mesma do `color_id` ao lado.
            $table->foreignId('material_id')
                ->nullable()
                ->after('color_id')
                ->constrained()
                ->restrictOnDelete();
        });

        // Enquanto a cor ainda sabe o material dela.
        DB::statement(
            'update variants set material_id = ('
            .'select material_id from colors where colors.id = variants.color_id'
            .') where color_id is not null'
        );
    }

    /**
     * Reverse the migrations.
     *
     * Reversivel na pratica: o material de cada variante era derivavel da cor
     * no momento em que esta migracao correu. A perda existe se alguem escrever
     * um material que a cor nao daria antes do rollback.
     */
    public function down(): void
    {
        // Em duas chamadas: no SQLite cada uma reconstroi a tabela, e largar a
        // chave estrangeira e a coluna no mesmo Blueprint deixa o grammar a
        // procurar uma coluna que ja nao existe.
        Schema::table('variants', function (Blueprint $table): void {
            $table->dropForeign(['material_id']);
        });

        Schema::table('variants', function (Blueprint $table): void {
            $table->dropColumn('material_id');
        });
    }
};
