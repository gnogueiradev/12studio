<?php

namespace App\PageBuilder;

/**
 * O contentor da arvore da pagina: uma caixa flex que leva outros
 * contentores ou elementos. Aqui vivem os limites da arvore e as
 * propriedades de estilo do contentor.
 */
final class Container
{
    public const MAX_DEPTH = 4;

    public const MAX_NODES = 400;

    public const MAX_ROW_CHILDREN = 6;

    /**
     * @return array<int, StyleProp>
     */
    public static function styleProps(): array
    {
        $alignText = ['start' => 'À esquerda', 'center' => 'Ao centro', 'end' => 'À direita'];

        return [
            StyleProp::enum('direction', 'Direção', ['row' => 'Em linha', 'column' => 'Em coluna'], 'column'),
            StyleProp::toggle('wrap', 'Quebrar linha'),
            StyleProp::int('gap', 'Espaço entre filhos', 0, 120),
            StyleProp::enum('justify', 'Distribuição', [
                'start' => 'Início', 'center' => 'Centro', 'end' => 'Fim', 'between' => 'Espaçado',
            ]),
            StyleProp::enum('align', 'Alinhamento', [
                'stretch' => 'Esticar', 'start' => 'Início', 'center' => 'Centro', 'end' => 'Fim',
            ]),
            // Ausente = largura automatica.
            StyleProp::int('width', 'Largura', 10, 100, '%'),
            StyleProp::enum('max_width', 'Largura máxima', ['contained' => 'Contido', 'full' => 'Ecrã inteiro'], 'contained'),
            StyleProp::int('min_height', 'Altura mínima', 0, 1200),
            StyleProp::int('padding_y', 'Espaço vertical interior', 0, 200),
            StyleProp::int('padding_x', 'Espaço horizontal interior', 0, 120),
            StyleProp::token('bg', 'Fundo', Block::BACKGROUNDS),
            StyleProp::media('bg_image', 'Imagem de fundo'),
            StyleProp::int('overlay', 'Escurecer a imagem', 0, 90, '%'),
            StyleProp::int('radius', 'Cantos arredondados', 0, 48),
            StyleProp::enum('text_align', 'Alinhamento do texto', $alignText),
        ];
    }

    /**
     * Para o editor.
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'styleProps' => array_map(fn (StyleProp $prop): array => $prop->toArray(), self::styleProps()),
            'limits' => [
                'maxDepth' => self::MAX_DEPTH,
                'maxNodes' => self::MAX_NODES,
                'maxRowChildren' => self::MAX_ROW_CHILDREN,
            ],
        ];
    }
}
