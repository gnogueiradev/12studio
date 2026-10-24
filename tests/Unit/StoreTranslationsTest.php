<?php

namespace Tests\Unit;

use Illuminate\Support\Arr;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Os textos da montra e as mensagens de validacao existem com as mesmas chaves
 * em todos os idiomas. Uma chave esquecida num idioma aparecia no ecra tal
 * como esta ("nav.catalog") a quem visita nessa lingua.
 */
class StoreTranslationsTest extends TestCase
{
    /**
     * @return array<string, array{string, array<int, string>}>
     */
    public static function files(): array
    {
        return [
            'store' => ['store', ['pt', 'es', 'en', 'fr']],
            // O ingles vem do Laravel: so os tres que escrevemos.
            'validation' => ['validation', ['pt', 'es', 'fr']],
        ];
    }

    /**
     * @param  array<int, string>  $locales
     */
    #[DataProvider('files')]
    public function test_every_language_has_the_same_keys(string $file, array $locales): void
    {
        $reference = $this->keys($file, $locales[0]);
        $this->assertNotEmpty($reference);

        foreach (array_slice($locales, 1) as $locale) {
            $keys = $this->keys($file, $locale);

            $this->assertSame([], array_values(array_diff($reference, $keys)), "Faltam chaves em lang/$locale/$file.php");
            $this->assertSame([], array_values(array_diff($keys, $reference)), "Chaves a mais em lang/$locale/$file.php");
        }
    }

    public function test_every_configured_language_has_store_texts(): void
    {
        foreach (array_keys((array) config('locales.available')) as $locale) {
            $this->assertFileExists(lang_path("$locale/store.php"));
        }
    }

    /**
     * @return array<int, string>
     */
    private function keys(string $file, string $locale): array
    {
        $keys = array_keys(Arr::dot(require lang_path("$locale/$file.php")));
        sort($keys);

        return $keys;
    }
}
