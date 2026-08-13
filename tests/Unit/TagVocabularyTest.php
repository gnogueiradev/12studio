<?php

namespace Tests\Unit;

use App\Models\Tag;
use App\Services\TagService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O vocabulario das etiquetas: nomes escritos a mao -> ids, por ambito.
 *
 * O que aqui se protege e a fronteira entre ambitos. Sem ela, escrever "natal"
 * numa ficha de cliente reaproveitaria a etiqueta do catalogo e as duas listas
 * de sugestoes fundiam-se — que e exatamente o problema que o `scope` resolve.
 */
class TagVocabularyTest extends TestCase
{
    use RefreshDatabase;

    private TagService $tags;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tags = new TagService;
    }

    public function test_a_new_name_creates_the_tag_in_the_given_scope(): void
    {
        $ids = $this->tags->idsFor(Tag::SCOPE_CUSTOMER, ['Revendedor']);

        $this->assertCount(1, $ids);
        $this->assertDatabaseHas('tags', [
            'id' => $ids[0],
            'scope' => Tag::SCOPE_CUSTOMER,
            'name' => 'Revendedor',
            'slug' => 'revendedor',
        ]);
    }

    public function test_the_same_name_in_two_scopes_are_two_tags(): void
    {
        $product = $this->tags->idsFor(Tag::SCOPE_PRODUCT, ['urgente']);
        $order = $this->tags->idsFor(Tag::SCOPE_ORDER, ['urgente']);

        $this->assertNotSame($product[0], $order[0]);
        $this->assertDatabaseCount('tags', 2);
    }

    public function test_the_same_name_in_one_scope_is_reused(): void
    {
        $first = $this->tags->idsFor(Tag::SCOPE_ORDER, ['Urgente']);
        $second = $this->tags->idsFor(Tag::SCOPE_ORDER, ['urgente']);

        $this->assertSame($first[0], $second[0]);
        $this->assertDatabaseCount('tags', 1);
        // O nome guardado e o da primeira criacao: quem escreve a seguir esta a
        // referir-se a etiqueta que ja existe, nao a rebatiza-la.
        $this->assertDatabaseHas('tags', ['name' => 'Urgente']);
    }

    public function test_names_differing_only_in_case_collapse_within_one_call(): void
    {
        $ids = $this->tags->idsFor(Tag::SCOPE_PRODUCT, ['Natal', 'natal', 'NATAL']);

        $this->assertCount(1, $ids);
        $this->assertDatabaseCount('tags', 1);
    }

    public function test_accents_and_spacing_collapse_into_the_same_slug(): void
    {
        $ids = $this->tags->idsFor(Tag::SCOPE_PRODUCT, ['  Edição Limitada  ', 'edicao limitada']);

        $this->assertCount(1, $ids);
        $this->assertDatabaseHas('tags', ['slug' => 'edicao-limitada']);
    }

    public function test_names_that_slug_to_nothing_are_dropped(): void
    {
        // "***" nao da slug nenhum. Deixar passar criava uma etiqueta de slug
        // vazio, e a segunda rebentava contra o unique.
        $ids = $this->tags->idsFor(Tag::SCOPE_PRODUCT, ['***', 'natal', '   ']);

        $this->assertCount(1, $ids);
        $this->assertDatabaseCount('tags', 1);
    }

    public function test_suggestions_are_limited_to_their_scope(): void
    {
        $this->tags->idsFor(Tag::SCOPE_PRODUCT, ['natal', 'minimalista']);
        $this->tags->idsFor(Tag::SCOPE_CUSTOMER, ['revendedor']);

        $this->assertSame(['minimalista', 'natal'], $this->tags->suggestions(Tag::SCOPE_PRODUCT));
        $this->assertSame(['revendedor'], $this->tags->suggestions(Tag::SCOPE_CUSTOMER));
        $this->assertSame([], $this->tags->suggestions(Tag::SCOPE_ORDER));
    }
}
