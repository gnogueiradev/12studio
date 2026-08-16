<?php

namespace Tests\Unit;

use App\Support\ColorMaterialMatrix;
use PHPUnit\Framework\TestCase;

/**
 * A interseccao que substituiu o produto cartesiano da matriz de variantes.
 *
 * Testa-se sem base de dados porque e disso que se trata: `combos()` recebe o
 * catalogo ja lido (`availableFor()` e que vai a pivo) e so decide o que
 * sobrevive. O que aqui se protege e a REGRA — um par que o dono nao tem nunca
 * chega a virar variante — e a ORDEM, que a pre-visualizacao do modal espelha.
 */
class ColorMaterialMatrixTest extends TestCase
{
    public function test_deixa_cair_o_par_que_o_dono_nao_tem(): void
    {
        // O rosa (1) so existe em PLA (10). O preto (2) existe nos dois.
        $combos = ColorMaterialMatrix::combos(
            colorIds: [1, 2],
            materialIds: [10, 20],
            sizes: [],
            available: [1 => [10], 2 => [10, 20]],
        );

        $this->assertSame([
            ['color_id' => 1, 'material_id' => 10, 'size_label' => null],
            ['color_id' => 2, 'material_id' => 10, 'size_label' => null],
            ['color_id' => 2, 'material_id' => 20, 'size_label' => null],
        ], $combos);
    }

    /**
     * Cor por fora, material no meio, tamanho por dentro — a mesma ordem que a
     * pre-visualizacao de product-create-dialog.tsx percorre. Se uma mudar sem
     * a outra, a matriz que o admin viu deixa de ser a que se gravou.
     */
    public function test_percorre_cor_depois_material_depois_tamanho(): void
    {
        $combos = ColorMaterialMatrix::combos(
            colorIds: [1, 2],
            materialIds: [10, 20],
            sizes: ['P', 'G'],
            available: [1 => [10, 20], 2 => [10, 20]],
        );

        $this->assertSame([
            [1, 10, 'P'], [1, 10, 'G'], [1, 20, 'P'], [1, 20, 'G'],
            [2, 10, 'P'], [2, 10, 'G'], [2, 20, 'P'], [2, 20, 'G'],
        ], array_map(array_values(...), $combos));
    }

    /**
     * Uma cor por declarar nao gera nada: nao se sabe em que filamento a
     * imprimir. E o outro lado da moeda de `syncMaterials()`, onde o conjunto
     * vazio nao esconde nada — la nao ha prova para destruir, aqui nao ha prova
     * para criar.
     */
    public function test_cor_sem_materiais_declarados_nao_gera_nada(): void
    {
        $combos = ColorMaterialMatrix::combos(
            colorIds: [1, 2],
            materialIds: [10],
            sizes: [],
            available: [2 => [10]],
        );

        $this->assertSame([['color_id' => 2, 'material_id' => 10, 'size_label' => null]], $combos);
    }

    public function test_devolve_vazio_quando_nenhum_par_sobrevive(): void
    {
        $combos = ColorMaterialMatrix::combos(
            colorIds: [1],
            materialIds: [20],
            sizes: ['P', 'G'],
            available: [1 => [10]],
        );

        $this->assertSame([], $combos);
    }

    /** Um material que a cor tem mas que nao foi escolhido fica de fora. */
    public function test_ignora_material_disponivel_que_nao_foi_escolhido(): void
    {
        $combos = ColorMaterialMatrix::combos(
            colorIds: [1],
            materialIds: [10],
            sizes: [],
            available: [1 => [10, 20]],
        );

        $this->assertSame([['color_id' => 1, 'material_id' => 10, 'size_label' => null]], $combos);
    }

    public function test_sem_cores_ou_sem_materiais_nao_ha_matriz(): void
    {
        $this->assertSame([], ColorMaterialMatrix::combos([], [10], [], [1 => [10]]));
        $this->assertSame([], ColorMaterialMatrix::combos([1], [], [], [1 => [10]]));
    }

    /**
     * O `hasAny()` existe para a validacao e para a UI responderem a mesma
     * pergunta sem montarem a matriz inteira so para a contarem.
     */
    public function test_has_any_responde_sem_montar_a_matriz(): void
    {
        $this->assertTrue(ColorMaterialMatrix::hasAny([1, 2], [10, 20], [1 => [10]]));
        $this->assertFalse(ColorMaterialMatrix::hasAny([1, 2], [20], [1 => [10], 2 => [10]]));
        $this->assertFalse(ColorMaterialMatrix::hasAny([1], [10], []));
    }
}
