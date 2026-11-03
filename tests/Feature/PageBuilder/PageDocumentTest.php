<?php

namespace Tests\Feature\PageBuilder;

use App\PageBuilder\PageDocument;
use PHPUnit\Framework\TestCase;

/**
 * O documento v2 e uma arvore de containers e elementos. A conversao dos
 * blocos planos (v1) tem de ser estavel e idempotente.
 */
class PageDocumentTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function hero(string $id = 'a'): array
    {
        return [
            'id' => $id,
            'type' => 'hero',
            'v' => 1,
            'visible' => true,
            'variant' => 'centered',
            'style' => ['bg' => 'brand', 'spacing' => 'lg', 'width' => 'full', 'align' => 'center'],
            'settings' => [],
            'content' => ['pt' => ['title' => 'Ola']],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $children
     * @return array<string, mixed>
     */
    private function container(string $id, array $children = []): array
    {
        return [
            'id' => $id,
            'type' => 'container',
            'v' => 2,
            'visible' => true,
            'settings' => [],
            'content' => [],
            'style' => [],
            'advanced' => [],
            'children' => $children,
        ];
    }

    public function test_v1_blocks_are_wrapped_in_transparent_root_containers(): void
    {
        $out = PageDocument::upgrade([$this->hero()]);

        $this->assertCount(1, $out);
        $this->assertSame('c-a', $out[0]['id']);
        $this->assertSame('container', $out[0]['type']);
        $this->assertSame(2, $out[0]['v']);
        $this->assertSame([], $out[0]['style']);
        $this->assertSame('a', $out[0]['children'][0]['id']);
        $this->assertSame($this->hero()['style'], $out[0]['children'][0]['style']);
    }

    public function test_upgrade_is_idempotent(): void
    {
        $v1 = [$this->hero('a'), $this->hero('b')];

        $this->assertSame(PageDocument::upgrade($v1), PageDocument::upgrade(PageDocument::upgrade($v1)));
    }

    public function test_mixed_roots_only_wrap_non_containers(): void
    {
        $existing = $this->container('keep', [$this->hero('x')]);

        $out = PageDocument::upgrade([$existing, $this->hero('b')]);

        $this->assertSame($existing, $out[0]);
        $this->assertSame('c-b', $out[1]['id']);
    }

    public function test_walk_visits_in_preorder_with_paths(): void
    {
        $nodes = [
            $this->container('c1', [$this->hero('a'), $this->hero('b')]),
            $this->container('c2'),
        ];
        $paths = [];
        $depths = [];

        PageDocument::walk($nodes, function (array $node, string $path, int $depth) use (&$paths, &$depths): void {
            $paths[] = $path;
            $depths[] = $depth;
        });

        $this->assertSame(['blocks.0', 'blocks.0.children.0', 'blocks.0.children.1', 'blocks.1'], $paths);
        $this->assertSame([0, 1, 1, 0], $depths);
    }

    public function test_find_and_count_cover_nested_nodes(): void
    {
        $nodes = [
            $this->container('c1', [$this->container('c2', [$this->hero('deep')])]),
            $this->container('c3'),
        ];

        $this->assertSame(4, PageDocument::count($nodes));
        $this->assertSame('deep', PageDocument::find($nodes, 'deep')['id'] ?? null);
        $this->assertNull(PageDocument::find($nodes, 'nope'));
    }
}
