<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Em que filamentos e que cada cor existe.
 *
 * Ha rosa em PLA e em PLA Matte, mas nao ha rosa em PLA Silk. Sem esta tabela o
 * catalogo nao sabe disso, e a matriz de criacao de produto — um produto
 * cartesiano de Cor x Material x Tamanho — inventava "Rosa Silk": uma variante
 * com referencia, preco e stock que nao se consegue imprimir.
 *
 * NAO e o regresso da `colors.material_id` que a 000070 largou. Essa era 1:N e
 * obrigava a uma linha "Rosa" por cada bobine — o problema que a fusao da 000060
 * veio resolver. Esta e N:N: uma cor "Rosa", ligada as bobines em que existe.
 *
 * Nasce VAZIA de proposito. O dono declara o stock real cor a cor, e ate la
 * nenhuma variante ja gravada e tocada: nao ter declarado nao e o mesmo que ter
 * declarado que nao existe (ver ColorService::syncMaterials).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('color_material', function (Blueprint $table): void {
            // Sem `id` e sem timestamps: a linha e o par, e a data em que se
            // ligou uma cor a uma bobine nao responde a pergunta nenhuma.
            //
            // restrictOnDelete nos dois lados, como em `variants.color_id` e
            // `variants.material_id` — regra global de eliminacao logica.
            $table->foreignId('color_id')->constrained()->restrictOnDelete();
            $table->foreignId('material_id')->constrained()->restrictOnDelete();

            $table->primary(['color_id', 'material_id']);

            // O sentido inverso — "que cores tenho nesta bobine" — e o que o
            // seletor de material pergunta quando a cor ja esta escolhida.
            $table->index('material_id', 'color_material_material_idx');
        });
    }

    /**
     * Reverse the migrations.
     *
     * Perde o catalogo declarado a mao. Nao ha de onde o derivar de volta: as
     * variantes existentes dizem que pares FORAM usados, nao que pares existem.
     */
    public function down(): void
    {
        Schema::dropIfExists('color_material');
    }
};
