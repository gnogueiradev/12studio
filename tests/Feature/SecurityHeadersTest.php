<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Guarda dos cabecalhos de seguranca. A montra renderiza HTML escrito no editor
 * do backoffice com dangerouslySetInnerHTML: o HTMLPurifier do ProductService
 * limpa-o antes de tocar na base de dados, e o CSP e a segunda rede — a que
 * apanha o que a primeira deixasse passar.
 */
class SecurityHeadersTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function alwaysPresentHeaders(): array
    {
        return [
            'nosniff' => ['X-Content-Type-Options'],
            'clickjacking' => ['X-Frame-Options'],
            'referrer' => ['Referrer-Policy'],
            'permissions' => ['Permissions-Policy'],
        ];
    }

    #[DataProvider('alwaysPresentHeaders')]
    public function test_the_storefront_carries_the_security_headers(string $header): void
    {
        $this->get('/')->assertOk()->assertHeader($header);
    }

    public function test_the_login_page_carries_them_too(): void
    {
        // O /login e a pagina que interessa: e a unica porta de entrada da loja
        // enquanto nao ha contas de cliente.
        $this->get('/login')
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_the_content_security_policy_starts_in_report_only(): void
    {
        $response = $this->get('/')->assertOk();

        $response->assertHeader('Content-Security-Policy-Report-Only');
        $this->assertNull(
            $response->headers->get('Content-Security-Policy'),
            'O CSP passou a valer sem antes ter havido uma passagem manual a recolher violacoes.',
        );
    }

    public function test_the_policy_closes_the_openings_that_matter(): void
    {
        $policy = (string) $this->get('/')->headers->get('Content-Security-Policy-Report-Only');

        $this->assertStringContainsString("frame-ancestors 'none'", $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
        $this->assertStringContainsString("base-uri 'self'", $policy);

        // A folga que nunca pode aparecer: com ela o CSP deixava de proteger
        // contra XSS, que e a unica razao pela qual existe aqui.
        $this->assertStringNotContainsString("script-src 'unsafe-inline'", $policy);
        $this->assertStringNotContainsString('unsafe-eval', $policy);
    }

    public function test_hsts_is_absent_over_plain_http(): void
    {
        // Metade da guarda que impede envenenar o localhost de quem desenvolve:
        // um HSTS gravado a partir de dev obriga TODOS os projetos da maquina a
        // HTTPS, e so se desfaz em chrome://net-internals/#hsts.
        $this->get('/')->assertOk()->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_hsts_is_absent_outside_production_even_over_https(): void
    {
        // A outra metade: TLS sozinho nao chega. Um staging em HTTPS no mesmo
        // dominio nao pode gravar HSTS pela producao.
        $this->get('/', ['X-Forwarded-Proto' => 'https'])
            ->assertOk()
            ->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_hsts_is_sent_in_production_over_https(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->get('/', ['X-Forwarded-Proto' => 'https'])
            ->assertOk()
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }
}
