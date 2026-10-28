<?php

namespace Tests\Unit;

use Illuminate\Support\Arr;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Cada t('chave') da montra tem de existir no lang/pt/store.php (o
 * StoreTranslationsTest garante que os outros idiomas tem as mesmas). Uma
 * chave errada aparecia no ecra tal como esta — "detail.interest" num botao.
 *
 * So as chaves literais: as montadas com `${...}` nao se conseguem ler aqui.
 */
class StoreTranslationKeysTest extends TestCase
{
    public function test_every_literal_key_used_in_the_store_exists(): void
    {
        $messages = require lang_path('pt/store.php');
        $missing = [];

        $files = Finder::create()
            ->files()
            ->in([
                resource_path('js/blocks'),
                resource_path('js/components/store'),
                resource_path('js/layouts'),
                resource_path('js/pages/store'),
            ])
            ->name('*.tsx');

        foreach ($files as $file) {
            preg_match_all("/\bt(?:\.choice)?\(\s*'([a-z0-9_.]+)'/", $file->getContents(), $matches);

            foreach ($matches[1] as $key) {
                if (! Arr::has($messages, $key) || is_array(Arr::get($messages, $key))) {
                    $missing[] = $file->getRelativePathname().': '.$key;
                }
            }
        }

        $this->assertSame([], $missing);
    }
}
