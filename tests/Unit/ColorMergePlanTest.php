<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * O planeamento da fusao de cores (migracao 2026_08_13_000060).
 *
 * Testa-se o `plan()` e nao a migracao inteira porque o RefreshDatabase aplica
 * SEMPRE todas as migracoes: no momento em que um teste corre, `colors` ja nao
 * tem `material_id` e nao ha forma limpa de montar o estado antigo. O risco
 * desta migracao esta em ESCOLHER quem sobrevive, e e isso que esta aqui — os
 * dois updates e o delete que aplicam a escolha sao SQL trivial.
 */
class ColorMergePlanTest extends TestCase
{
    /**
     * `require` e nao `require_once`: a segunda chamada devolvia `true` em vez
     * da migracao, e todos os testes menos o primeiro rebentavam.
     */
    private function migration(): object
    {
        return require dirname(__DIR__, 2).'/database/migrations/2026_08_13_000060_merge_colors_by_name.php';
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{id: int, name: string, hex_color: string, image: string|null, is_active: bool, sort_order: int}
     */
    private function row(int $id, string $name, array $overrides = []): array
    {
        /** @var array{id: int, name: string, hex_color: string, image: string|null, is_active: bool, sort_order: int} $row */
        $row = [
            'id' => $id,
            'name' => $name,
            'hex_color' => '#111111',
            'image' => null,
            'is_active' => true,
            'sort_order' => 0,
            ...$overrides,
        ];

        return $row;
    }

    /**
     * O menor id sobrevive: e o representante que o backoffice ja usava, e o
     * que faz um /admin/cores/{color} guardado continuar a abrir a mesma cor.
     */
    public function test_the_lowest_id_survives(): void
    {
        $plan = $this->migration()->plan([
            $this->row(3, 'Preto'),
            $this->row(7, 'Preto'),
            $this->row(9, 'Preto'),
        ]);

        $this->assertSame([3], array_column($plan['survivors'], 'id'));
        $this->assertSame([7 => 3, 9 => 3], $plan['remap']);
        $this->assertEqualsCanonicalizing([7, 9], $plan['delete']);
    }

    public function test_the_name_and_hex_come_from_the_survivor(): void
    {
        $plan = $this->migration()->plan([
            $this->row(3, 'Terracota', ['hex_color' => '#C1643C']),
            $this->row(7, 'Terracota', ['hex_color' => '#FFFFFF']),
        ]);

        $this->assertSame('Terracota', $plan['survivors'][0]['name']);
        $this->assertSame('#C1643C', $plan['survivors'][0]['hex_color']);
    }

    /**
     * OR e nao AND: `is_active` queria dizer "existe NESTA bobine" e passa a
     * querer dizer "existe". Um AND arquivava cores em uso ativo.
     */
    public function test_is_active_is_the_or_of_the_group(): void
    {
        $plan = $this->migration()->plan([
            $this->row(3, 'Preto', ['is_active' => false]),
            $this->row(7, 'Preto', ['is_active' => true]),
        ]);

        $this->assertTrue($plan['survivors'][0]['is_active']);
    }

    public function test_a_group_archived_everywhere_stays_archived(): void
    {
        $plan = $this->migration()->plan([
            $this->row(3, 'Malva', ['is_active' => false]),
            $this->row(7, 'Malva', ['is_active' => false]),
        ]);

        $this->assertFalse($plan['survivors'][0]['is_active']);
    }

    public function test_the_image_is_the_first_non_null_by_id(): void
    {
        $plan = $this->migration()->plan([
            $this->row(3, 'Preto'),
            $this->row(7, 'Preto', ['image' => 'preto.jpg']),
            $this->row(9, 'Preto', ['image' => 'outra.jpg']),
        ]);

        $this->assertSame('preto.jpg', $plan['survivors'][0]['image']);
    }

    /**
     * O sort_order antigo era um ordinal DENTRO de um material, por isso
     * dezenas de cores diferentes empatavam a zero. Reatribuir denso e o que
     * lhe devolve significado agora que a ordem e global.
     */
    public function test_the_sort_order_is_reassigned_dense_from_zero(): void
    {
        $plan = $this->migration()->plan([
            $this->row(1, 'Preto', ['sort_order' => 0]),
            $this->row(2, 'Terracota', ['sort_order' => 0]),
            $this->row(3, 'Dourado', ['sort_order' => 0]),
        ]);

        // Empate a zero: desempata o nome.
        $this->assertSame(
            [['Dourado', 0], ['Preto', 1], ['Terracota', 2]],
            array_map(
                fn (array $row): array => [$row['name'], $row['sort_order']],
                $plan['survivors'],
            ),
        );
    }

    public function test_the_smallest_sort_order_of_the_group_decides_the_order(): void
    {
        $plan = $this->migration()->plan([
            $this->row(1, 'Preto', ['sort_order' => 5]),
            $this->row(2, 'Preto', ['sort_order' => 0]),
            $this->row(3, 'Bege', ['sort_order' => 2]),
        ]);

        $this->assertSame(
            ['Preto', 'Bege'],
            array_column($plan['survivors'], 'name'),
        );
    }

    /**
     * Insensivel a maiusculas porque o indice que vem a seguir e COLLATE
     * NOCASE: agrupar por nome exato deixava os dois sobreviverem e o indice
     * nao chegava a nascer.
     */
    public function test_names_are_grouped_ignoring_case_and_padding(): void
    {
        $plan = $this->migration()->plan([
            $this->row(1, 'Preto'),
            $this->row(2, 'preto'),
            $this->row(3, ' PRETO '),
        ]);

        $this->assertCount(1, $plan['survivors']);
        $this->assertSame('Preto', $plan['survivors'][0]['name']);
        $this->assertEqualsCanonicalizing([2, 3], $plan['delete']);
    }

    public function test_a_group_of_one_passes_through_untouched(): void
    {
        $plan = $this->migration()->plan([
            $this->row(4, 'Dourado', ['hex_color' => '#C9A227', 'image' => 'ouro.jpg']),
        ]);

        $this->assertSame([], $plan['remap']);
        $this->assertSame([], $plan['delete']);
        $this->assertSame('#C9A227', $plan['survivors'][0]['hex_color']);
        $this->assertSame('ouro.jpg', $plan['survivors'][0]['image']);
    }

    /** O sobrevivente nunca entra no remap nem no delete. */
    public function test_the_survivor_is_never_remapped_or_deleted(): void
    {
        $plan = $this->migration()->plan([
            $this->row(3, 'Preto'),
            $this->row(7, 'Preto'),
        ]);

        $this->assertArrayNotHasKey(3, $plan['remap']);
        $this->assertNotContains(3, $plan['delete']);
    }

    public function test_an_empty_table_plans_nothing(): void
    {
        $plan = $this->migration()->plan([]);

        $this->assertSame([], $plan['survivors']);
        $this->assertSame([], $plan['remap']);
        $this->assertSame([], $plan['delete']);
    }
}
