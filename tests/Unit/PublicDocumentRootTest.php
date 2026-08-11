<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PublicDocumentRootTest extends TestCase
{
    /**
     * Teste-guarda de infraestrutura: nenhum bind mount pode tapar o
     * document root.
     *
     * Um bind mount NAO funde o host com a imagem — monta por cima. Montar o
     * public/ do host (que o bootstrap-env.sh cria VAZIO) por cima de
     * /app/public escondia o index.php que a imagem traz. O nginx nao dava
     * 404 nenhum: o `try_files ... /index.php` e um redirect interno, seguia
     * para o FastCGI na mesma, e era o php-fpm que respondia 404 a todos os
     * pedidos — site inteiro em baixo, /up incluido.
     *
     * A receita veio do qrcode, onde o APP_DIR do host e um checkout do repo
     * e portanto ja tem o index.php. Aqui o APP_DIR e um diretorio de estado
     * criado pelo pipeline. Copiar o mount sem copiar a pre-condicao custou
     * um deploy inteiro a reverter pelo health gate.
     */
    public function test_no_mount_hides_the_document_root(): void
    {
        $documentRoot = $this->documentRoot();

        $offenders = [];

        foreach ($this->composeMountTargets() as $target) {
            // Montar ABAIXO do document root (ex.: /app/public/build) e
            // legitimo — so tapa o que esta nesse subdiretorio. O que parte o
            // site e montar o proprio document root ou um pai dele.
            if ($target === $documentRoot || str_starts_with($documentRoot, rtrim($target, '/').'/')) {
                $offenders[] = $target;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Estes mounts tapam o document root ({$documentRoot}) e com ele o index.php da imagem:\n"
                .implode("\n", $offenders),
        );
    }

    /**
     * O outro lado da mesma falha: se o front controller nao chegar a entrar
     * na imagem, o resultado e identico (php-fpm a responder 404 a tudo).
     */
    public function test_the_image_ships_the_front_controller(): void
    {
        $this->assertFileExists(
            $this->basePath('public/index.php'),
            'O front controller do Laravel desapareceu do public/.',
        );

        $ignored = preg_split('/\R/', $this->fileContents('.dockerignore')) ?: [];

        foreach ($ignored as $line) {
            $pattern = trim($line);

            if ($pattern === '' || str_starts_with($pattern, '#')) {
                continue;
            }

            $this->assertSame(
                0,
                (int) fnmatch($pattern, 'public/index.php'),
                "O .dockerignore exclui o front controller do build context: {$pattern}",
            );
        }
    }

    /**
     * Alvos (lado do container) de todos os mounts declarados no compose.
     *
     * @return list<string>
     */
    private function composeMountTargets(): array
    {
        preg_match_all(
            '/^\s*-\s*(?<spec>[^\s#]+:[^\s#]+)\s*$/m',
            $this->fileContents('docker-compose.yml'),
            $matches,
        );

        $targets = [];

        foreach ($matches['spec'] as $spec) {
            $parts = explode(':', $spec);

            // <origem>:<destino>[:<opcoes>] — so o destino interessa.
            if (isset($parts[1]) && str_starts_with($parts[1], '/')) {
                $targets[] = $parts[1];
            }
        }

        return $targets;
    }

    private function documentRoot(): string
    {
        $dockerfile = $this->fileContents('Dockerfile');

        if (preg_match('/^\s*ENV\s+WEB_DOCUMENT_ROOT=(?<root>\S+)/m', $dockerfile, $matches) !== 1) {
            $this->fail('O Dockerfile tem de fixar ENV WEB_DOCUMENT_ROOT.');
        }

        return trim($matches['root'], '"\'');
    }

    private function fileContents(string $relativePath): string
    {
        $path = $this->basePath($relativePath);

        $this->assertFileExists($path, "{$relativePath} em falta.");

        return (string) file_get_contents($path);
    }

    private function basePath(string $relativePath): string
    {
        return dirname(__DIR__, 2).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    }
}
