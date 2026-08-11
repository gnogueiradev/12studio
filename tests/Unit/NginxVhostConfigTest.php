<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class NginxVhostConfigTest extends TestCase
{
    /**
     * Diretivas que a imagem base (webdevops/php-nginx) ja declara nos seus
     * proprios ficheiros de vhost.common.d. O vhost inclui
     * `vhost.common.d/*.conf` TODO dentro do mesmo bloco `server`, e o nginx
     * recusa-se a arrancar (emerg) se qualquer uma destas aparecer duas vezes
     * no mesmo contexto — o container fica em loop de reinicio e o /up nunca
     * responde.
     *
     * @var array<string, string>
     */
    private const BASE_IMAGE_DIRECTIVES = [
        // 10-general.conf — vem de SERVICE_NGINX_CLIENT_MAX_BODY_SIZE.
        'client_max_body_size' => '/^\s*client_max_body_size\b/m',
        // 10-location-root.conf
        'location /' => '/^\s*location\s+\/\s*\{/m',
        // 10-php.conf
        'location ~ \.php$' => '/^\s*location[^\n{]*\\\\\.php/m',
    ];

    /**
     * Teste-guarda de infraestrutura: nenhum .conf nosso pode redeclarar uma
     * diretiva que a imagem base ja poe no mesmo bloco server. Isto ja partiu
     * um deploy uma vez (nginx: [emerg] "client_max_body_size" directive is
     * duplicate) — a falha so aparecia no health check, depois de o container
     * antigo ja ter sido trocado.
     */
    public function test_no_shipped_vhost_config_redeclares_base_image_directives(): void
    {
        $offenders = [];

        foreach ($this->vhostConfigsCopiedByTheDockerfile() as $relativePath) {
            $path = $this->basePath($relativePath);

            if (! is_file($path)) {
                $offenders[] = "{$relativePath} (COPY no Dockerfile aponta para um ficheiro inexistente)";

                continue;
            }

            $contents = (string) file_get_contents($path);

            foreach (self::BASE_IMAGE_DIRECTIVES as $directive => $pattern) {
                if (preg_match($pattern, $contents) === 1) {
                    $offenders[] = "{$relativePath}: {$directive}";
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Diretivas duplicadas face a imagem base — o nginx nao arranca:\n".implode("\n", $offenders),
        );
    }

    /**
     * O limite de upload configura-se pela env var que a imagem substitui no
     * template, nunca por um .conf nosso. Se este valor desaparecer, o limite
     * passa a depender do default (silencioso) da imagem base.
     */
    public function test_the_upload_limit_is_set_through_the_env_var(): void
    {
        $dockerfile = $this->dockerfile();

        if (preg_match('/^\s*ENV\s+SERVICE_NGINX_CLIENT_MAX_BODY_SIZE=(\S+)/m', $dockerfile, $matches) !== 1) {
            $this->fail('O Dockerfile tem de fixar ENV SERVICE_NGINX_CLIENT_MAX_BODY_SIZE.');
        }

        $this->assertMatchesRegularExpression(
            '/^"?\d+[kmKM]"?$/',
            $matches[1],
            'SERVICE_NGINX_CLIENT_MAX_BODY_SIZE tem de ser um tamanho do nginx (ex.: 25m).',
        );
    }

    /**
     * @return list<string>
     */
    private function vhostConfigsCopiedByTheDockerfile(): array
    {
        preg_match_all(
            '/^\s*COPY\s+(?<sources>.+?)\s+(?<target>\S*vhost\.common\.d\S*)\s*$/m',
            $this->dockerfile(),
            $matches,
            PREG_SET_ORDER,
        );

        $sources = [];

        foreach ($matches as $match) {
            foreach (preg_split('/\s+/', trim($match['sources'])) ?: [] as $source) {
                // Flags do COPY (--from=build, --chown=...) nao sao ficheiros.
                if ($source === '' || str_starts_with($source, '--')) {
                    continue;
                }

                $sources[] = $source;
            }
        }

        return $sources;
    }

    private function dockerfile(): string
    {
        $path = $this->basePath('Dockerfile');

        $this->assertFileExists($path, 'Dockerfile em falta.');

        return (string) file_get_contents($path);
    }

    private function basePath(string $relativePath): string
    {
        return dirname(__DIR__, 2).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    }
}
