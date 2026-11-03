<?php

namespace Tests\Unit;

use App\PageBuilder\BlockRegistry;
use App\PageBuilder\Field;
use Tests\TestCase;

/**
 * O registo de blocos (PHP) e os componentes (React) tem de andar juntos: um
 * bloco sem componente nao aparecia na montra; um icone sem par no frontend
 * aparecia como estrela.
 */
class BlockComponentsTest extends TestCase
{
    public function test_every_registered_block_has_a_component(): void
    {
        $source = (string) file_get_contents(resource_path('js/blocks/index.tsx'));
        preg_match('/BLOCK_COMPONENTS[^{]*\{(.*?)\};/s', $source, $map);
        preg_match_all('/^\s*([a-z_]+):/m', $map[1] ?? '', $keys);

        $types = array_keys(app(BlockRegistry::class)->all());

        sort($types);
        $components = $keys[1];
        sort($components);

        $this->assertSame($types, $components);
    }

    public function test_every_icon_exists_in_the_frontend(): void
    {
        $source = (string) file_get_contents(resource_path('js/blocks/icons.tsx'));
        preg_match('/BLOCK_ICONS = \{(.*?)\}/s', $source, $map);
        preg_match_all("/^\s*'?([a-z-]+)'?:/m", $map[1] ?? '', $keys);

        $icons = Field::ICONS;
        sort($icons);
        $frontend = $keys[1];
        sort($frontend);

        $this->assertSame($icons, $frontend);
    }
}
