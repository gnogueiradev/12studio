<?php

namespace Tests\Feature;

use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Teste-guarda de infraestrutura: o BLOCO DE HEADERS da resposta tem de caber
 * no buffer com que o nginx le a resposta do php-fpm (fastcgi_buffer_size, 4096
 * por omissao). Se nao couber, o nginx DEITA FORA uma resposta 200 perfeitamente
 * valida e devolve 502 ao browser:
 *
 *   upstream sent too big header while reading response header from upstream,
 *   request: "GET /login", upstream: "fastcgi://127.0.0.1:9000"
 *
 * Aconteceu em producao: o /login ficou inacessivel enquanto o php-fpm respondia
 * 200 e o /up continuava verde — o health check bate numa rota leve, por isso
 * nada disparou. Em dev tambem nao aparece: o servidor do Herd nao tem FastCGI
 * pelo meio, logo nao ha buffer nenhum onde nao caber.
 */
class ResponseHeaderSizeTest extends TestCase
{
    /**
     * Abaixo do fastcgi_buffer_size default (4096) de proposito: o objetivo e
     * avisar ANTES de o nginx recusar, nao no minuto em que recusa.
     */
    private const HEADER_BUDGET_BYTES = 3072;

    /**
     * O AddLinkHeadersForPreloadedAssets vem no esqueleto do starter kit e
     * enumera NUM UNICO header `Link` todos os assets que o Vite pre-carrega.
     * Esse header cresce com a app: na Fase 1 ja ocupava 2 439 dos 3 585 bytes
     * de headers da pagina inicial, e no /login passava dos 4096 e rebentava.
     *
     * Tirar o middleware nao desliga o preload — as 22 tags
     * `<link rel="modulepreload">` continuam a ir no HTML, que e de onde o
     * browser as le. So se perde o adianto de as receber antes do parse do HTML.
     */
    public function test_the_web_group_does_not_add_link_headers_for_preloaded_assets(): void
    {
        // O grupo so chega ao router quando o Kernel arranca a tratar de um
        // pedido (SyncMiddlewareToRouter). Perguntar antes disso devolvia um
        // array vazio — e o teste passava sem testar nada.
        $this->get('/');

        $web = app('router')->getMiddlewareGroups()['web'] ?? [];

        $this->assertNotEmpty($web, 'O grupo `web` chegou vazio — o teste nao estaria a verificar nada.');

        $this->assertNotContains(
            AddLinkHeadersForPreloadedAssets::class,
            $web,
            'O Link header de preload cresce com a app e rebenta o buffer do nginx: 502 no /login.',
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function pagesServedToTheBrowser(): array
    {
        return [
            'montra' => ['/'],
            'login' => ['/login'],
        ];
    }

    #[DataProvider('pagesServedToTheBrowser')]
    public function test_pages_stay_within_the_fastcgi_header_budget(string $uri): void
    {
        // O Tests\TestCase desliga o Vite para os testes nao dependerem de
        // assets compilados. Aqui e precisamente o contrario: sem os assets
        // reais nao ha header `Link` nenhum e este teste media 1 028 bytes
        // constantes — passava sempre, inclusive com o bug em producao.
        if (! is_file(base_path('public/build/manifest.json'))) {
            $this->markTestSkipped('Sem manifest do Vite (corre `npm run build`) nao ha headers de assets para medir.');
        }

        $this->withVite();

        // O cadeado esta desligado nos testes (ver phpunit.xml), logo o /login
        // renderiza como renderiza para quem ja passou pelo /acesso/<segredo>.
        $response = $this->get($uri);

        $response->assertOk();

        $bytes = 0;

        foreach ($response->headers->allPreserveCase() as $name => $values) {
            foreach ($values as $value) {
                // "Nome: valor\r\n" — e assim que o nginx os conta.
                $bytes += strlen($name) + strlen((string) $value) + 4;
            }
        }

        $this->assertLessThan(
            self::HEADER_BUDGET_BYTES,
            $bytes,
            "Os headers de {$uri} ocupam {$bytes} bytes. Acima do fastcgi_buffer_size ".
            'o nginx devolve 502 a uma resposta que o PHP gerou bem.',
        );
    }
}
