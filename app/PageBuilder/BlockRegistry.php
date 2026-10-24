<?php

namespace App\PageBuilder;

/**
 * Os tipos de bloco que existem. A ordem e a do catalogo no editor.
 */
class BlockRegistry
{
    /** @var array<int, class-string<Block>> */
    public const BLOCKS = [
        Blocks\HeroBlock::class,
        Blocks\ProductGridBlock::class,
        Blocks\CategoriesBlock::class,
        Blocks\TextBlock::class,
        Blocks\TextImageBlock::class,
        Blocks\BannerBlock::class,
        Blocks\GalleryBlock::class,
        Blocks\StepsBlock::class,
        Blocks\FaqBlock::class,
        Blocks\TestimonialsBlock::class,
        Blocks\TrustBlock::class,
        Blocks\ContactBlock::class,
        Blocks\CustomOrderBlock::class,
        Blocks\SpacerBlock::class,
    ];

    /** @var array<string, Block>|null */
    private ?array $blocks = null;

    /**
     * @return array<string, Block>
     */
    public function all(): array
    {
        if ($this->blocks === null) {
            $this->blocks = [];

            foreach (self::BLOCKS as $class) {
                $block = app($class);
                $this->blocks[$block->type()] = $block;
            }
        }

        return $this->blocks;
    }

    public function get(string $type): ?Block
    {
        return $this->all()[$type] ?? null;
    }

    /**
     * Os blocos que podem entrar num template.
     *
     * @return array<string, Block>
     */
    public function forTemplate(string $template): array
    {
        return array_filter($this->all(), fn (Block $block): bool => in_array($template, $block->templates(), true));
    }

    /**
     * O esquema completo para o editor.
     *
     * @return array<int, array<string, mixed>>
     */
    public function schema(string $template): array
    {
        return array_values(array_map(fn (Block $block): array => $block->schema(), $this->forTemplate($template)));
    }
}
