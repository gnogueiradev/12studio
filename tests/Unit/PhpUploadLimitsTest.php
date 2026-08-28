<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Teste-guarda de infraestrutura, irmao do NginxVhostConfigTest.
 *
 * O nginx e o PHP tem CADA UM o seu tecto de corpo de pedido, em ficheiros
 * diferentes, e o mais baixo dos dois e que manda. Estavam divergentes: o
 * Dockerfile subia o nginx para 25m e o PHP ficava nos defaults dele (2M de
 * ficheiro, 8M de corpo), por isso um upload de 5M — o maximo que o
 * StoreProductImageRequest valida — passava o nginx e morria no PHP com um
 * $_FILES vazio.
 *
 * A ordem certa e a que este teste exige: os tectos do PHP ABAIXO do do nginx.
 * Assim quem excede leva um 413 do nginx, que e um erro com nome, em vez de um
 * formulario que volta vazio sem dizer porque.
 */
class PhpUploadLimitsTest extends TestCase
{
    public function test_the_php_limits_stay_below_the_nginx_one(): void
    {
        $nginx = $this->bytes($this->nginxBodyLimit());
        $upload = $this->bytes($this->phpDirective('upload_max_filesize'));
        $post = $this->bytes($this->phpDirective('post_max_size'));

        $this->assertLessThanOrEqual(
            $nginx,
            $post,
            'post_max_size acima do client_max_body_size do nginx: o nginx corta primeiro e o valor do PHP e ficcao.',
        );

        $this->assertLessThanOrEqual(
            $post,
            $upload,
            'upload_max_filesize acima do post_max_size: um ficheiro dentro do limite pode na mesma nao caber no pedido.',
        );
    }

    /**
     * O limite do PHP tem de dar para o que a app aceita. O
     * StoreProductImageRequest valida `max:5120` (5 MB) por fotografia, ate 10
     * de cada vez — se o tecto do PHP descesse abaixo disso, a validacao nunca
     * chegava a correr.
     */
    public function test_the_limits_fit_what_the_app_accepts(): void
    {
        $this->assertGreaterThanOrEqual(
            5 * 1024 * 1024,
            $this->bytes($this->phpDirective('upload_max_filesize')),
            'upload_max_filesize abaixo dos 5 MB que o StoreProductImageRequest aceita por fotografia.',
        );
    }

    public function test_the_php_version_is_not_advertised(): void
    {
        $this->assertSame(
            'off',
            mb_strtolower($this->phpDirective('expose_php')),
            'expose_php ligado: o header X-Powered-By anuncia a versao exata do PHP.',
        );
    }

    private function nginxBodyLimit(): string
    {
        $dockerfile = (string) file_get_contents($this->basePath('Dockerfile'));

        if (preg_match('/^\s*ENV\s+SERVICE_NGINX_CLIENT_MAX_BODY_SIZE=(\S+)/m', $dockerfile, $matches) !== 1) {
            $this->fail('O Dockerfile deixou de fixar SERVICE_NGINX_CLIENT_MAX_BODY_SIZE.');
        }

        return trim($matches[1], '"\'');
    }

    private function phpDirective(string $name): string
    {
        $ini = $this->basePath('docker/php.ini');

        $this->assertFileExists($ini, 'docker/php.ini em falta — o Dockerfile copia-o para /opt/docker/etc/php/php.ini.');

        $contents = (string) file_get_contents($ini);

        if (preg_match('/^\s*'.preg_quote($name, '/').'\s*=\s*(\S+)/m', $contents, $matches) !== 1) {
            $this->fail("docker/php.ini deixou de declarar {$name}.");
        }

        return $matches[1];
    }

    /**
     * Converte os sufixos que o nginx e o PHP partilham (K, M, G).
     */
    private function bytes(string $size): int
    {
        $size = trim($size);
        $unit = mb_strtolower(mb_substr($size, -1));
        $value = (int) $size;

        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }

    private function basePath(string $relativePath): string
    {
        return dirname(__DIR__, 2).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    }
}
