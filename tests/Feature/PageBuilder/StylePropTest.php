<?php

namespace Tests\Feature\PageBuilder;

use App\PageBuilder\Block;
use App\PageBuilder\BlockRegistry;
use App\PageBuilder\Container;
use App\PageBuilder\StyleProp;
use Tests\TestCase;

/**
 * StyleProp: valida um valor por dispositivo; Container e blocos expoem as
 * suas propriedades de estilo ao editor.
 */
class StylePropTest extends TestCase
{
    public function test_int_prop_keeps_valid_devices_and_drops_out_of_range(): void
    {
        $prop = StyleProp::int('padding_y', 'Espaco vertical', 0, 200);
        $errors = [];

        $clean = $prop->clean(['desktop' => 40, 'tablet' => 999, 'phone' => 10], 'blocks.0.style.padding_y', $errors);

        $this->assertSame(['desktop' => 40], $clean);
        $this->assertArrayHasKey('blocks.0.style.padding_y.tablet', $errors);
        $this->assertCount(1, $errors);
    }

    public function test_int_prop_accepts_numeric_strings_and_rejects_the_rest(): void
    {
        $prop = StyleProp::int('gap', 'Espaco', 0, 120);
        $errors = [];

        $clean = $prop->clean(['desktop' => '24', 'tablet' => 12.0, 'mobile' => 'abc'], 'p', $errors);

        $this->assertSame(['desktop' => 24, 'tablet' => 12], $clean);
        $this->assertArrayHasKey('p.mobile', $errors);
    }

    public function test_token_prop_refuses_free_colours(): void
    {
        $prop = StyleProp::token('bg', 'Fundo', Block::BACKGROUNDS);
        $errors = [];

        $clean = $prop->clean(['desktop' => '#ff0000', 'tablet' => 'dark'], 'p', $errors);

        $this->assertSame(['tablet' => 'dark'], $clean);
        $this->assertArrayHasKey('p.desktop', $errors);
    }

    public function test_enum_toggle_and_media_props(): void
    {
        $errors = [];

        $enum = StyleProp::enum('direction', 'Direcao', ['row' => 'Linha', 'column' => 'Coluna']);
        $this->assertSame(['desktop' => 'row'], $enum->clean(['desktop' => 'row', 'tablet' => 'diagonal'], 'e', $errors));
        $this->assertArrayHasKey('e.tablet', $errors);

        $toggle = StyleProp::toggle('wrap', 'Quebrar');
        $this->assertSame(['desktop' => true, 'tablet' => false], $toggle->clean(['desktop' => true, 'tablet' => 0], 't', $errors));
        $this->assertSame([], $toggle->clean(['desktop' => 'talvez'], 't2', $errors));
        $this->assertArrayHasKey('t2.desktop', $errors);

        $media = StyleProp::media('bg_image', 'Imagem');
        $this->assertSame(['desktop' => 7], $media->clean(['desktop' => '7', 'tablet' => 0, 'mobile' => 'x'], 'm', $errors));
        $this->assertArrayHasKey('m.tablet', $errors);
        $this->assertArrayHasKey('m.mobile', $errors);
    }

    public function test_non_array_input_gives_an_empty_result_without_error(): void
    {
        $errors = [];

        $this->assertSame([], StyleProp::toggle('wrap', 'Quebrar')->clean(null, 'p', $errors));
        $this->assertSame([], $errors);
    }

    public function test_schema_exposes_container_and_style_fields(): void
    {
        $container = Container::schema();
        $keys = collect($container['styleProps'])->pluck('key');

        $this->assertTrue($keys->contains('direction'));
        $this->assertSame(4, $container['limits']['maxDepth']);

        $schema = collect(app(BlockRegistry::class)->schema('page'))->keyBy('type');

        $this->assertTrue($schema['hero']['hasShell']);
        $this->assertFalse($schema['hero']['rootOnly']);
        $this->assertSame(
            ['margin_top', 'margin_bottom', 'padding_y', 'padding_x', 'hide'],
            collect($schema['hero']['advancedFields'])->pluck('key')->all(),
        );
        $this->assertSame([], $schema['hero']['styleFields']);

        $product = collect(app(BlockRegistry::class)->schema('product'))->keyBy('type');
        $this->assertTrue($product['product_main']['rootOnly']);
    }
}
