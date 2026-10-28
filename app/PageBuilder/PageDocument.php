<?php

namespace App\PageBuilder;

/**
 * O documento de uma pagina: uma arvore de containers e elementos (v2).
 *
 * Os blocos planos antigos (v1) sobem para v2 embrulhados num container de
 * raiz transparente, com ids deterministicos para a conversao ser estavel
 * entre leituras. Tudo aqui e puro e estatico.
 */
class PageDocument
{
    /**
     * Embrulha cada raiz que nao e container. Um documento v2 sai igual.
     *
     * @param  array<int, array<string, mixed>>  $nodes
     * @return array<int, array<string, mixed>>
     */
    public static function upgrade(array $nodes): array
    {
        return array_values(array_map(
            fn (array $node): array => ($node['type'] ?? null) === 'container' ? $node : self::wrapper($node),
            $nodes,
        ));
    }

    /**
     * Container de raiz transparente (largura total, sem fundo nem
     * preenchimento) a volta de um bloco v1.
     *
     * @param  array<string, mixed>  $child
     * @return array<string, mixed>
     */
    public static function wrapper(array $child): array
    {
        return [
            'id' => 'c-'.($child['id'] ?? ''),
            'type' => 'container',
            'v' => 2,
            'visible' => true,
            'settings' => [],
            'content' => [],
            'style' => [],
            'advanced' => [],
            'children' => [$child],
        ];
    }

    /**
     * Percorre a arvore em pre-ordem; $fn recebe o no, o caminho
     * ("blocks.0.children.1") e a profundidade.
     *
     * @param  array<int, array<string, mixed>>  $nodes
     * @param  callable(array<string, mixed>, string, int): void  $fn
     */
    public static function walk(array $nodes, callable $fn, string $path = 'blocks', int $depth = 0): void
    {
        foreach ($nodes as $index => $node) {
            $here = $path.'.'.$index;
            $fn($node, $here, $depth);

            if (isset($node['children']) && is_array($node['children'])) {
                self::walk($node['children'], $fn, $here.'.children', $depth + 1);
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $nodes
     * @return array<string, mixed>|null
     */
    public static function find(array $nodes, string $id): ?array
    {
        $found = null;

        self::walk($nodes, function (array $node) use ($id, &$found): void {
            if ($found === null && ($node['id'] ?? null) === $id) {
                $found = $node;
            }
        });

        return $found;
    }

    /**
     * @param  array<int, array<string, mixed>>  $nodes
     */
    public static function count(array $nodes): int
    {
        $total = 0;

        self::walk($nodes, function () use (&$total): void {
            $total++;
        });

        return $total;
    }

    /**
     * Os nos que nao sao contentores (o que o dono chama "blocos").
     *
     * @param  array<int, array<string, mixed>>  $nodes
     */
    public static function countElements(array $nodes): int
    {
        $total = 0;

        self::walk($nodes, function (array $node) use (&$total): void {
            if (($node['type'] ?? null) !== 'container') {
                $total++;
            }
        });

        return $total;
    }
}
