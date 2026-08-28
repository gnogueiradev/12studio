<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureLoginGate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Teste-guarda de infraestrutura, irmao do ResponseHeaderSizeTest: em producao
 * a app corre atras do Nginx Proxy Manager, que termina o TLS e fala com o
 * container em http simples.
 *
 * Sem o trustProxies() do bootstrap/app.php o Laravel acredita no que ve e
 * perde tres coisas de uma so vez — e nenhuma delas se nota em dev, onde o
 * Herd fala HTTP diretamente com o PHP e nao ha proxy nenhum pelo meio:
 *
 *   1. isSecure() da false e os cookies saem sem a flag Secure;
 *   2. ip() devolve o IP do proxy a TODOS os visitantes, o que achata a
 *      componente de IP dos rate limiters do FortifyServiceProvider;
 *   3. url() gera http:// nos links dos emails de reset e de verificacao.
 */
class TrustedProxiesTest extends TestCase
{
    /**
     * Rota descartavel: as tres perguntas so se podem fazer de dentro de um
     * pedido, e nenhuma rota real da app as expoe.
     */
    private function routeThatReportsTheRequest(): void
    {
        Route::get('_test/proxy', fn (Request $request) => [
            'secure' => $request->isSecure(),
            'ip' => $request->ip(),
            'url' => $request->url(),
        ]);
    }

    public function test_the_forwarded_proto_header_makes_the_request_secure(): void
    {
        $this->routeThatReportsTheRequest();

        $this->get('_test/proxy', ['X-Forwarded-Proto' => 'https'])
            ->assertOk()
            ->assertJsonPath('secure', true);
    }

    public function test_urls_are_generated_over_https_behind_the_proxy(): void
    {
        $this->routeThatReportsTheRequest();

        // O que faz a diferenca entre um email de reset que funciona e um que
        // manda o utilizador para http:// — e, com HSTS ligado, para um aviso
        // do browser.
        $response = $this->get('_test/proxy', ['X-Forwarded-Proto' => 'https']);

        $this->assertStringStartsWith('https://', $response->json('url'));
    }

    public function test_the_visitor_ip_comes_from_the_forwarded_header(): void
    {
        $this->routeThatReportsTheRequest();

        // Sem isto os limitadores de login e de passkeys viam sempre o mesmo
        // IP — o do container do proxy — e deixavam de distinguir visitantes.
        $this->get('_test/proxy', ['X-Forwarded-For' => '203.0.113.7'])
            ->assertOk()
            ->assertJsonPath('ip', '203.0.113.7');
    }

    public function test_a_plain_http_request_stays_insecure(): void
    {
        $this->routeThatReportsTheRequest();

        // A outra metade da guarda: confiar no proxy nao pode ser o mesmo que
        // dar tudo por seguro. Sem cabecalho encaminhado, http e http.
        $this->get('_test/proxy')
            ->assertOk()
            ->assertJsonPath('secure', false);
    }

    public function test_the_gate_cookie_is_secure_behind_the_proxy(): void
    {
        // A consequencia concreta que motivou tudo isto: o LoginGateController
        // passa $request->isSecure() ao cookie, e ate aqui recebia sempre
        // false em producao.
        config(['access.login_secret' => 'segredo-de-teste']);

        $response = $this->get('/acesso/segredo-de-teste', ['X-Forwarded-Proto' => 'https']);

        $cookie = collect($response->headers->getCookies())
            ->firstWhere(fn ($cookie) => $cookie->getName() === EnsureLoginGate::COOKIE);

        $this->assertNotNull($cookie, 'O cadeado nao gravou cookie nenhum.');
        $this->assertTrue($cookie->isSecure(), 'O cookie do cadeado saiu sem a flag Secure.');
        $this->assertTrue($cookie->isHttpOnly());
    }
}
