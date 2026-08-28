<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * O robots.txt nao e seguranca — quem quer entrar no /admin nao pede licenca a
 * um ficheiro de texto, e quem o impede sao o EnsureAdmin e os Form Requests.
 *
 * O que ele decide e o que fica em resultados de pesquisa, e ha uma decisao com
 * consequencia real: um `Disallow` e uma lista PUBLICA do que existe. Escrever
 * la o caminho do cadeado era publicar o segredo que o cadeado guarda.
 */
class RobotsTxtTest extends TestCase
{
    public function test_the_backoffice_is_not_offered_to_crawlers(): void
    {
        $robots = $this->robots();

        $this->assertStringContainsString('Disallow: /admin', $robots);
        $this->assertStringContainsString('Disallow: /settings', $robots);
    }

    /**
     * A guarda que interessa. O /acesso/<segredo> so funciona enquanto for
     * segredo; um Disallow com esse caminho entregava-o a qualquer pessoa que
     * abrisse o robots.txt — que e um ficheiro feito para ser lido.
     */
    public function test_the_login_gate_path_is_not_published(): void
    {
        $this->assertStringNotContainsString(
            '/acesso',
            $this->robots(),
            'O caminho do cadeado apareceu no robots.txt, que e publico por definicao.',
        );
    }

    private function robots(): string
    {
        $path = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'robots.txt';

        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
