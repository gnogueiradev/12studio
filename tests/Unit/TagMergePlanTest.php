<?php

namespace Tests\Unit;

use App\Support\TagMergePlan;
use PHPUnit\Framework\TestCase;

/**
 * A decisao de renomear vs fundir uma etiqueta. Sem base de dados: o que aqui
 * se protege e a escolha, nao o SQL que a aplica.
 */
class TagMergePlanTest extends TestCase
{
    public function test_a_free_name_is_just_a_rename(): void
    {
        $plan = TagMergePlan::for(1, 'Presente', ['natal' => 1]);

        $this->assertFalse($plan->isMerge());
        $this->assertSame('Presente', $plan->name);
        $this->assertSame('presente', $plan->slug);
    }

    public function test_a_name_taken_by_another_tag_is_a_merge(): void
    {
        $plan = TagMergePlan::for(1, 'natal', ['natl' => 1, 'natal' => 7]);

        $this->assertTrue($plan->isMerge());
        $this->assertSame(7, $plan->mergeInto);
    }

    /**
     * O caso que se erra: mudar so a caixa do nome da o mesmo slug, portanto a
     * propria etiqueta ocupa o lugar. Tratar isso como fusao apagava-a.
     */
    public function test_changing_only_the_case_of_its_own_name_is_not_a_merge(): void
    {
        $plan = TagMergePlan::for(3, 'Natal', ['natal' => 3]);

        $this->assertFalse($plan->isMerge());
        $this->assertSame('Natal', $plan->name);
        $this->assertSame('natal', $plan->slug);
    }

    public function test_accents_and_spacing_are_normalised_before_deciding(): void
    {
        $plan = TagMergePlan::for(1, '  Edição Limitada ', ['edicao-limitada' => 9]);

        $this->assertTrue($plan->isMerge());
        $this->assertSame(9, $plan->mergeInto);
        $this->assertSame('Edição Limitada', $plan->name);
    }

    public function test_the_slug_of_a_name_with_no_letters_is_empty(): void
    {
        // Quem decide o que fazer com isto e a validacao, nao o plano — mas o
        // plano tem de o dizer sem inventar um slug.
        $this->assertSame('', TagMergePlan::for(1, '***', [])->slug);
    }
}
